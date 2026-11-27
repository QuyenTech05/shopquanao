<?php

use PHPUnit\Framework\TestCase;

final class MariaDbPlanProbeTest extends TestCase
{
    public function testEstimatedAndActualRowsRemainDistinct(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/mariadb_plan_probe.php';
        $plan = ['query_block' => ['filesort' => ['temporary_table' => ['nested_loop' => [
            ['table' => ['table_name' => 'p', 'access_type' => 'range', 'possible_keys' => ['idx_price'], 'key' => 'idx_price', 'rows' => 100, 'r_rows' => 12.5, 'r_loops' => 2]],
        ]]]]];
        $actual = MariaDbPlanProbe::parseJson($plan, true);
        self::assertTrue($actual['filesort']);
        self::assertTrue($actual['temporary']);
        self::assertSame('idx_price', $actual['tables'][0]['key']);
        self::assertSame(100, $actual['tables'][0]['estimated_rows']);
        self::assertSame(12.5, $actual['tables'][0]['actual_rows_per_loop']);
        self::assertSame(25.0, $actual['tables'][0]['actual_rows_total']);
        self::assertNull(MariaDbPlanProbe::parseJson($plan, false)['tables'][0]['actual_rows_per_loop']);
    }

    public function testStandardExplainDoesNotInventActualRows(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/mariadb_plan_probe.php';
        $parsed = MariaDbPlanProbe::parseStandard([['table'=>'p','type'=>'ALL','possible_keys'=>null,'key'=>null,'rows'=>'100','Extra'=>'Using temporary; Using filesort']]);
        self::assertTrue($parsed['filesort']);
        self::assertTrue($parsed['temporary']);
        self::assertSame(100, $parsed['tables'][0]['estimated_rows']);
        self::assertNull($parsed['tables'][0]['actual_rows_total']);
    }
}
