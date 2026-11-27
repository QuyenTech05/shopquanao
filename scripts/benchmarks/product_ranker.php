<?php
/** Synthetic candidate-ranking benchmark. Does not connect to a shop database. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$url = $argv[1] ?? 'http://reranker:8000';
$names = [
    'Áo khoác có mũ', 'Mũ len', 'Mũ lưỡi trai', 'Áo thun cotton trắng', 'Áo thun graphic',
    'Áo khoác bomber kaki đen', 'Áo khoác denim xanh', 'Quần jeans slim đen', 'Quần jeans ống rộng',
    'Quần baggy vải tuyết mưa', 'Áo sơ mi linen', 'Áo sơ mi caro', 'Váy công sở', 'Váy jean',
    'Chân váy chữ A', 'Giày thể thao trắng', 'Áo hoodie', 'Áo dài tay cổ tim', 'Áo thun đen', 'Quần short kaki',
];
$products = [];
foreach ($names as $id => $name) {
    $products[] = ['id' => $id + 1, 'name' => $name, 'price' => 100000 + $id * 10000,
        'stock' => $id === 9 ? 0 : 10, 'available_sizes' => ['M', 'L']];
}
// Labels are 1-based candidate IDs. Filters are upstream of the scorer.
$cases = [
    ['mũ lưỡi trai', [3]], ['mu luoi trai', [3]], ['bomber', [6]], ['áo khoác đen', [6]],
    ['áo thun', [4, 5, 19]], ['quần jeans', [8, 9]], ['quan jean', [8, 9]],
    ['sơ mi', [11, 12]], ['váy công sở', [13]], ['baggy', [10]], ['cổ tim', [18]],
    ['giày trắng', [16]], ['short kaki', [20]], ['hoodie', [17]],
];
$health = @file_get_contents(rtrim($url, '/') . '/health', false, stream_context_create(['http' => ['timeout' => 3]]));
if (!$health || (json_decode($health, true)['status'] ?? '') !== 'ok') {
    fwrite(STDERR, "TF-IDF sidecar health check failed\n");
    exit(1);
}
$result = ['products' => $products, 'pagination' => ['total' => count($products)]];
$report = ['scope' => 'synthetic ranking only; no SQL, API, concurrency, or host CPU/RSS gate', 'cases' => count($cases)];
foreach (['tfidf', 'bm25'] as $mode) {
    $ranker = new ProductReranker($url, $mode);
    $times = [];
    $top1 = $hit5 = $cards = 0;
    $caseResults = [];
    foreach ($cases as [$query, $expected]) {
        $ranked = $ranker->apply($query, $result);
        $ids = array_column($ranked['products'], 'id');
        $top1 += (int)in_array($ids[0], $expected, true);
        $hit5 += (int)(array_intersect(array_slice($ids, 0, 5), $expected) !== []);
        $byId = array_column($ranked['products'], null, 'id');
        $cards += (int)(count($ids) === count($products) && $byId == array_column($products, null, 'id') && $ranked['pagination'] === $result['pagination']);
        $caseResults[] = ['query' => $query, 'expected' => $expected, 'top5' => array_slice($ids, 0, 5)];
    }
    for ($i = 0; $i < 10; $i++) {
        foreach ($cases as [$query]) {
            $start = hrtime(true);
            $ranker->apply($query, $result);
            $times[] = (hrtime(true) - $start) / 1e6;
        }
    }
    sort($times);
    $report[$mode] = ['top1' => $top1, 'hit5' => $hit5, 'unchanged_cards' => $cards,
        'samples' => count($times), 'p50_ms' => $times[(int)ceil(count($times)*0.5)-1],
        'p95_ms' => $times[(int)ceil(count($times)*0.95)-1], 'case_results' => $caseResults];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
