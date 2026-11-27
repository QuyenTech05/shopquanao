<?php
declare(strict_types=1);

final class SqlBenchmarkReport
{
    private static function number(mixed $value, int $digits = 3): string
    {
        return $value === null ? 'unavailable' : number_format((float)$value, $digits, '.', '');
    }

    private static function selected(array $measurement): array
    {
        return array_values(array_unique(array_filter(array_column($measurement['plan']['tables'], 'key'))));
    }

    private static function details(array $measurement, string $field): string
    {
        return implode('; ', array_map(static function (array $table) use ($field): string {
            $value = $table[$field] ?? null;
            return $table['table'] . '=' . ($value === null ? 'unavailable' : (is_array($value) ? implode(',', $value) : (string)$value));
        }, $measurement['plan']['tables'])) ?: 'unavailable';
    }

    private static function classification(array $baseline, array $indexed, bool $adopted): string
    {
        if ($baseline['inconclusive_reasons'] || $indexed['inconclusive_reasons']) {
            return 'inconclusive';
        }
        $delta95 = $baseline['p95_ms'] - $indexed['p95_ms'];
        $delta50 = $baseline['p50_ms'] - $indexed['p50_ms'];
        $threshold = max(0.05, $baseline['p95_ms'] * 0.10);
        if ($delta95 >= $threshold && $delta50 > 0 && $adopted) {
            return 'index win';
        }
        if ($delta95 <= -$threshold && $delta50 < 0 && $adopted) {
            return 'regression';
        }
        if (abs($delta95) < $threshold && abs($delta50) < max(0.05, $baseline['p50_ms'] * 0.10)) {
            return 'no material effect';
        }
        return 'inconclusive';
    }

    public static function rows(array $data): array
    {
        $removed = array_column($data['metadata']['verification']['removed_indexes'], 1);
        $rows = [];
        foreach ($data['queries'] as $id => $query) {
            $b = $query['baseline'];
            $a = $query['indexed'];
            $adopted = array_intersect(self::selected($a), $removed) !== [];
            $row = ['query_id'=>$id, 'group'=>$query['definition']['group'], 'source'=>$query['definition']['source'], 'result_count'=>$a['result_count']];
            foreach (['baseline'=>$b,'indexed'=>$a] as $variant=>$measurement) {
                foreach (['p50_ms','p95_ms','p99_ms'] as $metric) {
                    $row["{$variant}_$metric"] = self::number($measurement[$metric]);
                }
                $row["{$variant}_keys"] = self::details($measurement, 'key');
                $row["{$variant}_possible_keys"] = self::details($measurement, 'possible_keys');
                $row["{$variant}_access_types"] = self::details($measurement, 'access_type');
                $row["{$variant}_estimated_rows"] = self::details($measurement, 'estimated_rows');
                $row["{$variant}_actual_rows_per_loop"] = self::details($measurement, 'actual_rows_per_loop');
                $row["{$variant}_actual_rows_total"] = self::details($measurement, 'actual_rows_total');
                foreach (['Rows_examined','Rows_sent','Created_tmp_tables','Sort_rows','Sort_scan'] as $counter) {
                    $row["{$variant}_$counter"] = self::number($measurement['session_counter_mean_per_run'][$counter] ?? null, 2);
                }
                $row["{$variant}_temporary"] = $measurement['plan']['temporary'] ? 'yes' : 'no';
                $row["{$variant}_filesort"] = $measurement['plan']['filesort'] ? 'yes' : 'no';
            }
            $row['p95_reduction_pct'] = self::number($b['p95_ms'] > 0 ? 100 * ($b['p95_ms'] - $a['p95_ms']) / $b['p95_ms'] : null, 2);
            $baseExamined = $b['session_counter_mean_per_run']['Rows_examined'] ?? null;
            $indexExamined = $a['session_counter_mean_per_run']['Rows_examined'] ?? null;
            $row['rows_examined_reduction_pct'] = self::number($baseExamined !== null && $baseExamined > 0 && $indexExamined !== null ? 100 * ($baseExamined - $indexExamined) / $baseExamined : null, 2);
            $row['compared_index_adopted'] = $adopted ? 'yes' : 'no';
            $row['classification'] = self::classification($b, $a, $adopted);
            $rows[] = $row;
        }
        return $rows;
    }

    public static function render(array $data): string
    {
        $rows = self::rows($data);
        $count = count($rows);
        $meta = $data['metadata'];
        $cap = $data['capabilities']['indexed'];
        $adopted = count(array_filter($rows, static fn(array $r): bool => $r['compared_index_adopted'] === 'yes'));
        $categories = array_count_values(array_column($rows,'classification'));
        $output = "# SQL indexing benchmark\n\n";
        $output .= "MariaDB {$cap['server_version']}; plan method: {$cap['plan_method']}. Synthetic local workload, seed {$meta['seed']['seed']}, {$meta['warmups']} warmups and {$meta['runs']} measured runs per query and variant.\n\n";
        if (isset($meta['started_at_utc'], $meta['finished_at_utc'])) {
            $output .= "Run UTC: {$meta['started_at_utc']} to {$meta['finished_at_utc']}.\n\n";
        }
        if (isset($meta['verification']['row_counts'])) {
            $counts = $meta['verification']['row_counts'];
            $output .= "Verified fixture: " . count($counts) . " tables, " . number_format(array_sum($counts)) . " rows per variant. " . implode(', ', array_map(static fn($t, $n) => "$t=" . number_format($n), array_keys($counts), $counts)) . ".\n\n";
        }
        $output .= "Compared $count/20 catalog queries. Compared-index adoption: $adopted/$count (" . self::number(100*$adopted/max(1,$count),1) . "%). ";
        foreach (['index win','no material effect','regression','inconclusive'] as $category) {
            $output .= "$category: " . ($categories[$category] ?? 0) . "; ";
        }
        $output .= "\n\n";
        foreach (['baseline','indexed'] as $variant) {
            $fullScans = $anyIndex = $sum95 = $temporary = $filesort = 0;
            foreach ($data['queries'] as $query) {
                $measurement = $query[$variant];
                $fullScans += in_array('ALL', array_column($measurement['plan']['tables'],'access_type'), true) ? 1 : 0;
                $anyIndex += self::selected($measurement) !== [] ? 1 : 0;
                $sum95 += $measurement['p95_ms'];
                $temporary += $measurement['plan']['temporary'] ? 1 : 0;
                $filesort += $measurement['plan']['filesort'] ? 1 : 0;
            }
            $output .= "- $variant: $fullScans/$count queries with at least one ALL scan; $anyIndex/$count with at least one selected index; $temporary/$count with temporary tables; $filesort/$count with filesort; sum of per-query p95 = " . self::number($sum95) . " ms.\n";
        }
        $baseSum = array_sum(array_map(static fn($q) => $q['baseline']['p95_ms'], $data['queries']));
        $idxSum = array_sum(array_map(static fn($q) => $q['indexed']['p95_ms'], $data['queries']));
        $output .= "\nEqual-weight sum-of-p95 reduction: " . self::number($baseSum > 0 ? 100*($baseSum-$idxSum)/$baseSum : null, 2) . "%. This is a ratio of sums across the $count query patterns, not pooled request p95.\n\n";
        $output .= "Latency is execute plus complete fetch. Nearest-rank percentiles, alternating variant order, warm buffers, query cache disabled. Win/regression labels require a selected compared index, consistent p50 direction, and a p95 difference of at least 10% and 0.05 ms; small differences are no material effect, other cases inconclusive. Shared host load, synthetic selectivity and finite samples limit generalization.\n\n";
        $output .= "Plan rows are estimated rows per access and, when ANALYZE reports them, actual average rows per loop and rows × loops. These are separate from session Rows_examined. Session counters are means per measured run after stable snapshot overhead subtraction. unavailable means the engine/plan did not expose a reliable metric.\n\n";
        $output .= "| Query | Baseline p50/p95/p99 ms | Indexed p50/p95/p99 ms | p95 reduction % | Baseline → indexed selected keys | Baseline → indexed estimated rows | Baseline → indexed actual rows × loops | Rows examined (B→I) / reduction % | Rows sent (B→I) | Temp/filesort (B→I) | Outcome |\n";
        $output .= "|---|---:|---:|---:|---|---|---|---|---|---|---|\n";
        foreach ($rows as $r) {
            $b = implode('/', [$r['baseline_p50_ms'],$r['baseline_p95_ms'],$r['baseline_p99_ms']]);
            $a = implode('/', [$r['indexed_p50_ms'],$r['indexed_p95_ms'],$r['indexed_p99_ms']]);
            $cells = [$r['query_id'],$b,$a,$r['p95_reduction_pct']];
            foreach (['keys','estimated_rows','actual_rows_total','Rows_examined','Rows_sent'] as $field) {
                $cells[] = $r["baseline_$field"] . ' → ' . $r["indexed_$field"] . ($field === 'Rows_examined' ? ' / ' . $r['rows_examined_reduction_pct'] : '');
            }
            $cells[] = $r['baseline_temporary'].'/'.$r['baseline_filesort'].' → '.$r['indexed_temporary'].'/'.$r['indexed_filesort'];
            $cells[] = $r['classification'];
            $output .= '| ' . implode(' | ', array_map(static fn($s) => str_replace('|','\\|',(string)$s), $cells)) . " |\n";
        }
        if ($count === 20 && ($meta['verification']['verified'] ?? false) && $meta['runs'] === 30 && $meta['warmups'] === 5) {
            $output .= "\nCV-safe evidence: Benchmarked 20 MariaDB query patterns using {$cap['plan_method']} on identical synthetic datasets, recording selected indexes, plan row counts and p95 latency before and after removing eight secondary indexes.\n";
        } else {
            $output .= "\nBenchmark inconclusive for a full-workload CV claim.\n";
        }
        $output .= "\nCSV includes access types, possible keys, actual rows per loop and all captured counters. JSON contains raw plans, all samples, fixed parameters, execution order, result digests and verified fixture counts/checksums.\n";
        return $output;
    }

    public static function write(array $data, string $markdown, string $csv): void
    {
        if (file_put_contents($markdown, self::render($data)) === false) {
            throw new RuntimeException('Could not write Markdown report');
        }
        $rows = self::rows($data);
        $stream = fopen($csv, 'w');
        if ($stream === false) {
            throw new RuntimeException('Could not write CSV report');
        }
        try {
            fputcsv($stream, array_keys($rows[0]), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
        } finally {
            fclose($stream);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['input:', 'output:']);
    if (!isset($options['input'], $options['output'])) {
        throw new InvalidArgumentException('Required: --input=results.json --output=report.md');
    }
    $data = json_decode((string)file_get_contents($options['input']), true, 512, JSON_THROW_ON_ERROR);
    SqlBenchmarkReport::write($data, $options['output'], preg_replace('/\.md$/', '', $options['output']) . '.csv');
}
