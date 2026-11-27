<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductRerankerTest extends TestCase
{
    public function testBm25PreservesCardsAndRanksAccentFoldedExactName(): void
    {
        $products = [];
        foreach (['Áo khoác có mũ', 'Mũ len', 'Mũ lưỡi trai', 'Áo thun', 'Quần jeans'] as $i => $name) {
            $products[] = ['id' => $i + 1, 'name' => $name, 'price' => 100000 + $i, 'stock' => $i];
        }
        $result = ['products' => $products, 'pagination' => ['total' => 5]];
        $ranked = (new ProductReranker(null, 'bm25'))->apply('mu luoi trai', $result);
        self::assertSame($products[2], $ranked['products'][0]);
        self::assertSame($result['pagination'], $ranked['pagination']);
        self::assertEqualsCanonicalizing($products, $ranked['products']);
    }

    public function testBm25PreservesSqlOrderForTiesAndUnrankedTail(): void
    {
        $products = array_map(fn($id) => ['id' => $id, 'name' => 'Áo thun'], range(1, 25));
        $result = ['products' => $products];
        $ranker = new ProductReranker(null, 'bm25');
        self::assertSame($result, $ranker->apply('không trùng', $result));
        self::assertSame($result, $ranker->apply('', $result));
        self::assertSame(['products' => array_slice($products, 0, 4)], $ranker->apply('áo', ['products' => array_slice($products, 0, 4)]));
        self::assertSame($result, (new ProductReranker(null))->apply('áo', $result));
    }

    public function testModeDefaultsAndInvalidValueFallback(): void
    {
        $old = getenv('PRODUCT_RANKER_MODE');
        try {
            putenv('PRODUCT_RANKER_MODE');
            self::assertSame('tfidf', ServiceConfig::productRankerModeFromEnvironment());
            putenv('PRODUCT_RANKER_MODE=bm25');
            self::assertSame('bm25', ServiceConfig::productRankerModeFromEnvironment());
            putenv('PRODUCT_RANKER_MODE=invalid');
            self::assertSame('tfidf', ServiceConfig::productRankerModeFromEnvironment());
        } finally {
            putenv($old === false ? 'PRODUCT_RANKER_MODE' : 'PRODUCT_RANKER_MODE=' . $old);
        }
    }
}
