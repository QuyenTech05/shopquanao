<?php

use PHPUnit\Framework\TestCase;

final class SqlQueryCatalogTest extends TestCase
{
    public function testCatalogContainsTwentyBoundReadOnlyQueries(): void
    {
        require_once __DIR__ . '/../../../scripts/benchmarks/sql_queries.php';
        $queries = SqlQueryCatalog::all();
        self::assertCount(20, $queries);
        $groups = [];
        foreach ($queries as $id => $query) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]+$/', $id);
            self::assertMatchesRegularExpression('/^SELECT\s/i', $query['sql']);
            self::assertSame(substr_count($query['sql'], '?'), count($query['params']), $id);
            self::assertNotEmpty($query['expected_tables']);
            self::assertNotEmpty($query['description']);
            self::assertNotEmpty($query['source']);
            $groups[] = $query['group'];
        }
        foreach (['product/catalog', 'variants', 'orders/cart', 'chat/session', 'support/FAQ', 'joins/aggregates'] as $group) {
            self::assertContains($group, $groups);
        }
        foreach (['product_filter', 'variant_availability', 'order_lookup', 'chat_history', 'support_ticket_status'] as $id) {
            self::assertArrayHasKey($id, $queries);
        }
    }
}
