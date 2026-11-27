<?php

use PHPUnit\Framework\TestCase;

final class SqlBenchmarkReportTest extends TestCase
{
    public function testTwentyQueriesAndMissingMetricsAreReported(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/render_sql_report.php';
        require_once __DIR__ . '/../../../scripts/benchmarks/sql_queries.php';
        $measurement = [
            'p50_ms'=>1.0,'p95_ms'=>2.0,'p99_ms'=>3.0,'result_count'=>1,
            'plan'=>['tables'=>[['table'=>'p','key'=>'idx_price','access_type'=>'range','estimated_rows'=>10,'actual_rows_per_loop'=>null,'actual_rows_total'=>null]],'temporary'=>false,'filesort'=>false],
            'session_counter_mean_per_run'=>['Rows_examined'=>null,'Rows_sent'=>1],
            'inconclusive_reasons'=>[],
        ];
        $results = ['metadata'=>['runs'=>30,'warmups'=>5,'seed'=>['seed'=>20261127],'verification'=>['removed_indexes'=>[['products','idx_price']]]],'capabilities'=>['indexed'=>['server_version'=>'10.11','plan_method'=>'EXPLAIN FORMAT=JSON','supports_analyze'=>false]],'queries'=>[]];
        foreach (SqlQueryCatalog::all() as $id=>$definition) {
            $results['queries'][$id] = ['definition'=>$definition,'indexed'=>$measurement,'baseline'=>$measurement];
        }
        $report = SqlBenchmarkReport::render($results);
        foreach (array_keys(SqlQueryCatalog::all()) as $id) {
            self::assertStringContainsString($id, $report);
        }
        self::assertStringContainsString('20/20', $report);
        self::assertStringContainsString('unavailable', $report);
        self::assertStringContainsString('estimated', $report);
        self::assertCount(20, SqlBenchmarkReport::rows($results));
    }
}
