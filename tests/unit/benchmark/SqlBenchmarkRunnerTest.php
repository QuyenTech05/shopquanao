<?php

use PHPUnit\Framework\TestCase;

final class SqlBenchmarkRunnerTest extends TestCase
{
    public function testPercentilesAndOrder(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/run_sql_benchmark.php';
        self::assertSame(15.0, SqlBenchmarkRunner::percentile(range(1,30), 0.50));
        self::assertSame(29.0, SqlBenchmarkRunner::percentile(range(1,30), 0.95));
        self::assertSame(30.0, SqlBenchmarkRunner::percentile(range(1,30), 0.99));
        self::assertSame(['indexed','baseline'], SqlBenchmarkRunner::order(0));
        self::assertSame(['baseline','indexed'], SqlBenchmarkRunner::order(1));
        self::assertCount(20, SqlQueryCatalog::all());
    }

    public function testMissingCountersAndMalformedOptions(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/run_sql_benchmark.php';
        self::assertSame(['Rows_sent'=>4,'Rows_examined'=>null], SqlBenchmarkRunner::counterDelta(['Rows_sent'=>10,'Rows_examined'=>null], ['Rows_sent'=>14,'Rows_examined'=>null], ['Rows_sent'=>0,'Rows_examined'=>null]));
        $this->expectException(InvalidArgumentException::class);
        new BenchmarkOptions(0,5,20261127);
    }

    public function testUnknownQueryIsRejected(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/run_sql_benchmark.php';
        $this->expectException(InvalidArgumentException::class);
        new QueryDefinition('unknown');
    }
}
