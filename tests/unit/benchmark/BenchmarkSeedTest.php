<?php

use PHPUnit\Framework\TestCase;

final class BenchmarkSeedTest extends TestCase
{
    public function testManifestAndRowsAreDeterministicAndSynthetic(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/seed_sql_benchmark.php';
        $plan = new BenchmarkSeed('large', 20261127);
        self::assertSame($plan->manifest(), (new BenchmarkSeed('large', 20261127))->manifest());
        $counts = $plan->manifest()['counts'];
        foreach (['products' => 50000, 'product_variants' => 200000, 'orders' => 100000, 'chat_messages' => 250000, 'support_tickets' => 50000, 'faqs' => 10000] as $table => $minimum) {
            self::assertGreaterThanOrEqual($minimum, $counts[$table]);
        }
        $first = $plan->rows('users')->current();
        self::assertSame($first, (new BenchmarkSeed('large', 20261127))->rows('users')->current());
        self::assertStringEndsWith('@example.invalid', $first[2]);
        self::assertSame('disabled-synthetic-account', $first[3]);
        self::assertSame(20261127, $plan->manifest()['seed']);
    }
}
