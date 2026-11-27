<?php


/** Ranks a bounded set of product candidates; falls back to original order. */
final class ProductReranker
{
    /** Tối thiểu bao nhiêu kết quả thì kích hoạt rerank */
    private const RERANK_MIN_RESULTS = 5;

    /** Timeout cho reranker sidecar (ms) — fallback về gốc nếu quá chậm */
    private const RERANK_TIMEOUT_MS = 2000;
    /** Tối đa bao nhiêu items gửi xuống reranker (phần còn lại giữ nguyên thứ tự) */
    private const RERANK_MAX_ITEMS = 20;

    /** @param ?string $url sidecar base URL (`/rerank` is appended); null disables the reranker */
    public function __construct(private ?string $url, private string $mode = 'tfidf')
    {
    }

    /**
     * Apply reranker sidecar nếu có đủ kết quả.
     * Fallback về thứ tự gốc nếu reranker không phản hồi.
     */
    public function apply(string $query, array $result): array {
        if (count($result['products']) < self::RERANK_MIN_RESULTS) {
            return $result; // quá ít, không cần rerank
        }

        // Limit items gửi xuống reranker để tránh timeout
        $productsToRerank = array_slice($result["products"], 0, self::RERANK_MAX_ITEMS);

        $texts = array_map(fn($p) => $p['name'] ?? '', $productsToRerank);
        $texts = array_values($texts); // re-index

        try {
            $sorted = $this->mode === 'bm25'
                ? $this->rankBm25($query, $texts)
                : $this->callReranker($query, $texts);
            if ($sorted === null) {
                return $result; // fallback
            }

            // Require a complete permutation before changing any product card.
            $expected = range(0, count($texts) - 1);
            $indices = $sorted;
            sort($indices, SORT_NUMERIC);
            if ($indices !== $expected) {
                return $result;
            }
            $candidates = array_values($productsToRerank);
            $reranked = array_map(fn($index) => $candidates[$index], $sorted);
            $reranked = array_merge($reranked, array_slice($result['products'], self::RERANK_MAX_ITEMS));

            $result["products"] = $reranked;
        } catch (Throwable $e) {
            error_log("Reranker fallback: " . $e->getMessage());
        }

        return $result;
    }

    /** BM25 over the at most 20 already filtered names; no catalog-wide index or HTTP request. */
    private function rankBm25(string $query, array $texts): array
    {
        $queryTerms = array_unique($this->terms($query));
        $documents = array_map(fn($text) => $this->terms($text), $texts);
        $frequencies = array_map('array_count_values', $documents);
        $count = count($documents);
        $averageLength = max(1.0, array_sum(array_map('count', $documents)) / $count);
        $scores = array_fill(0, $count, 0.0);
        $k1 = 1.2;
        $b = 0.75;
        foreach ($queryTerms as $term) {
            // Long partial words (e.g. "jean" -> "jeans") match word prefixes.
            $termFrequencies = array_map(function ($tf) use ($term) {
                $total = 0;
                foreach ($tf as $word => $frequency) {
                    if ((string)$word === $term || (mb_strlen($term) >= 4 && str_starts_with((string)$word, $term))) {
                        $total += $frequency;
                    }
                }
                return $total;
            }, $frequencies);
            $df = count(array_filter($termFrequencies));
            $idf = log(1 + ($count - $df + 0.5) / ($df + 0.5));
            foreach ($termFrequencies as $index => $frequency) {
                $lengthNorm = 1 - $b + $b * count($documents[$index]) / $averageLength;
                $scores[$index] += $idf * ($frequency * ($k1 + 1)) / ($frequency + $k1 * $lengthNorm);
            }
        }
        $normalizedQuery = trim(ProductAttributeNormalizer::normalizeText($query));
        if ($normalizedQuery !== '') {
            foreach ($texts as $index => $text) {
                $normalized = trim(ProductAttributeNormalizer::normalizeText($text));
                // Prefer an exact name, then a contiguous phrase; maintain SQL ordering on ties.
                if ($normalized === $normalizedQuery) {
                    $scores[$index] += 4.0;
                } elseif (str_contains(' ' . $normalized . ' ', ' ' . $normalizedQuery . ' ')) {
                    $scores[$index] += 2.0;
                }
            }
        }
        $indices = array_keys($scores);
        usort($indices, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
        return $indices;
    }

    private function terms(string $text): array
    {
        $normalized = trim(ProductAttributeNormalizer::normalizeText($text));
        return preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Gọi Python sidecar reranker.
     * Trả về mảng indices đã sorted theo relevance (cao→thấp), hoặc null nếu lỗi.
     */
    private function callReranker(string $query, array $texts): ?array {
        $url = $this->getRerankerUrl();
        if ($url === null) return null;

        $payload = json_encode([
            'query' => $query,
            'texts' => $texts,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, InternalHttp::CURL_OPTIONS + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => intdiv(self::RERANK_TIMEOUT_MS, 1000) ?: 1, // 2 second max
            CURLOPT_CONNECTTIMEOUT => 1, // 1 second connect
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($raw === false || $raw === '' || $httpCode !== 200) {
            $body = $raw === false ? '' : $raw;
            error_log("Reranker HTTP $httpCode, body=" . substr($body, 0, 200));
            return null;
        }

        $data = json_decode($raw, true);
        if (!isset($data['sorted_indices']) || !is_array($data['sorted_indices'])) {
            return null;
        }

        // Log latency để monitoring
        if (isset($data['elapsed_ms'])) {
            error_log("Reranker latency: {$data['elapsed_ms']}ms for " . count($texts) . " items");
        }

        return $data['sorted_indices'];
    }

    /**
     * URL /rerank của reranker sidecar (cấu hình qua ServiceConfig), hoặc null khi tắt.
     */
    private function getRerankerUrl(): ?string {
        return $this->url === null ? null : rtrim($this->url, '/') . '/rerank';
    }
}
