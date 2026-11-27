<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KnowledgeRerankModeTest extends TestCase
{
    private string $dir;
    private string $url;
    private mixed $server = null;
    private array $cacheState = [];
    private array $envState = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/knowledge-test-' . bin2hex(random_bytes(8));
        mkdir($this->dir);
        foreach (['dir' => $this->dir, 'redis' => null, 'redisChecked' => true] as $key => $value) {
            $property = new ReflectionProperty(Cache::class, $key);
            $this->cacheState[$key] = $property->getValue();
            $property->setValue(null, $value);
        }
        foreach (['KNOWLEDGE_TEST_HTTP_LOG' => $this->dir . '/requests.log', 'RETRIEVAL_CACHE_TTL' => '3600',
            'RERANK_CACHE_TTL' => '3600', 'KNOWLEDGE_RERANK_MODE' => 'cross_encoder'] as $key => $value) {
            $this->envState[$key] = getenv($key);
            putenv("$key=$value");
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->url = 'http://' . $address;
        $this->server = proc_open(
            [PHP_BINARY, '-S', $address, ROOT_DIR . '/tests/support/knowledge_http_stub.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes
        );
        $this->assertIsResource($this->server);
        for ($i = 0; $i < 100; $i++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->fail('HTTP fixture failed to start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ($this->cacheState as $key => $value) {
            (new ReflectionProperty(Cache::class, $key))->setValue(null, $value);
        }
        foreach ($this->envState as $key => $value) {
            putenv($value === false ? $key : "$key=$value");
        }
        if (isset($this->dir) && is_dir($this->dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function retriever(string $mode, bool $failRerank = false, string $suffix = '', ?string $token = null): KnowledgeRetriever
    {
        $config = new ServiceConfig(
            qdrantUrl: $this->url,
            ragMlUrl: $failRerank ? $this->url . '/unavailable' : $this->url . $suffix,
            ragMlToken: $token,
            embeddingProvider: 'local_hash',
            knowledgeRerankMode: $mode
        );
        // Fixed documents keep lexical and vector ordering deterministic.
        return new class(new PDO('sqlite::memory:'), $config) extends KnowledgeRetriever {
            public function getDocuments(): array
            {
                return [
                    ['title' => 'Shipping', 'content' => 'shipping policy', 'text' => 'Shipping shipping policy',
                        'category' => 'general', 'source' => 'fixture'],
                ];
            }
        };
    }

    private function rerankCalls(): int
    {
        $requests = file($this->dir . '/requests.log', FILE_IGNORE_NEW_LINES) ?: [];
        return count(array_filter($requests, static fn(string $path): bool => $path === '/rerank'));
    }

    public function testModesUseIndependentRetrievalCachesAndRrfPreservesEvidence(): void
    {
        $cross = $this->retriever('cross_encoder')->search('shipping', null, 1);
        $this->assertSame('hybrid_reranked', $cross['retrieval_mode']);
        $this->assertSame('Vector', $cross['results'][0]['title']);
        $this->assertSame(1, $this->rerankCalls());

        $rrf = $this->retriever('rrf')->search('shipping', null, 1);
        $this->assertFalse($rrf['cache_metrics']['retrieval_cache_hit']);
        $this->assertFalse($rrf['cache_metrics']['rerank_cache_hit']);
        $this->assertSame('hybrid_rrf', $rrf['retrieval_mode']);
        $this->assertNull($rrf['reranker_model']);
        $this->assertCount(1, $rrf['results']);
        $doc = $rrf['results'][0];
        $this->assertSame('Shipping', $doc['title']);
        $this->assertSame('hybrid_rrf', $doc['retrieval_mode']);
        $this->assertSame($doc['hybrid_score'], $doc['score']);
        $this->assertGreaterThan(0, $doc['lexical_score']);
        $this->assertGreaterThan(0, $doc['vector_score']);
        $this->assertNull($doc['rerank_score']);
        $this->assertSame(1, $this->rerankCalls());

        foreach (['cross_encoder' => 'hybrid_reranked', 'rrf' => 'hybrid_rrf'] as $mode => $expected) {
            $warm = $this->retriever($mode)->search('shipping', null, 1);
            $this->assertTrue($warm['cache_metrics']['retrieval_cache_hit']);
            $this->assertSame($expected, $warm['retrieval_mode']);
        }
        $this->assertSame(1, $this->rerankCalls());
    }

    public function testCrossEncoderReusesRerankCacheAndFallsBackOnFailure(): void
    {
        putenv('RETRIEVAL_CACHE_TTL=0');
        $cross = $this->retriever('cross_encoder');
        $cross->search('shipping', null, 2);
        $warm = $cross->search('shipping', null, 2);
        $this->assertTrue($warm['cache_metrics']['rerank_cache_hit']);
        $this->assertSame(1, $this->rerankCalls());
        $rrf = $this->retriever('rrf')->search('shipping', null, 2);
        $this->assertFalse($rrf['cache_metrics']['rerank_cache_hit']);
        $this->assertSame(['Shipping', 'Vector'], array_column($rrf['results'], 'title'));
        $this->assertSame(1, $this->rerankCalls());

        $failed = $this->retriever('cross_encoder', true)->search('shipping policy', null, 2);
        $this->assertSame('hybrid_no_rerank', $failed['retrieval_mode']);
        $this->assertSame(['Shipping', 'Vector'], array_column($failed['results'], 'title'));
    }

    public function testMalformedRerankDoesNotPoisonCacheAndAuthenticatedRetrySucceeds(): void
    {
        $bad = $this->retriever('cross_encoder', false, '/invalid')->search('shipping', null, 2);
        $this->assertSame('hybrid_no_rerank', $bad['retrieval_mode']);
        $this->assertSame(['Shipping', 'Vector'], array_column($bad['results'], 'title'));
        $unauthorized = $this->retriever('cross_encoder', false, '/auth')->search('shipping', null, 2);
        $this->assertSame('hybrid_no_rerank', $unauthorized['retrieval_mode']);
        $this->assertFalse($unauthorized['cache_metrics']['retrieval_cache_hit']);
        $valid = $this->retriever('cross_encoder', false, '/auth', 'fixture-token')->search('shipping', null, 2);
        $this->assertSame('hybrid_reranked', $valid['retrieval_mode']);
        $this->assertFalse($valid['cache_metrics']['rerank_cache_hit']);
        $this->assertFalse($valid['cache_metrics']['retrieval_cache_hit']);
    }

    public function testGuestPromptCacheIsPartitionedByMode(): void
    {
        $cache = new PromptCache();
        $cross = $cache->keyFor('shipping', [], '', []);
        putenv('KNOWLEDGE_RERANK_MODE=rrf');
        $this->assertNotSame($cross, $cache->keyFor('shipping', [], '', []));
        putenv('KNOWLEDGE_RERANK_MODE=cross_encoder');
        $this->assertSame($cross, $cache->keyFor('shipping', [], '', []));
    }
}
