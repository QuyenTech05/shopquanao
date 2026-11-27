<?php

use PHPUnit\Framework\TestCase;

final class SqlVariantBuilderTest extends TestCase
{
    public function testManifestDeclaresOnlyNamedSecondaryIndexes(): void
    {
        $manifest = file(__DIR__ . '/../../../scripts/benchmarks/index-manifest.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotEmpty($manifest);
        foreach ($manifest as $statement) {
            if (str_starts_with($statement, '--')) {
                continue;
            }
            self::assertMatchesRegularExpression('/^ALTER TABLE [a-z_]+ DROP INDEX idx_[a-z_]+;$/', $statement);
        }
    }

    public function testBuilderExistsAndHasTemporaryDirectoryGuard(): void
    {
        $script = __DIR__ . '/../../../scripts/benchmarks/create_sql_variants.sh';
        self::assertFileExists($script);
        if (!is_executable('/bin/bash')) {
            self::markTestSkipped('Shell guard is verified on the host; PHP image has no bash');
        }
        $workdir = sys_get_temp_dir() . '/sqlbench-test-' . bin2hex(random_bytes(6));
        mkdir($workdir);
        file_put_contents($workdir . '/keep', 'untouched');
        try {
            exec('bash ' . escapeshellarg($script) . ' --source-schema database/schema.sql --workdir ' . escapeshellarg($workdir) . ' 2>&1', $output, $status);
            self::assertNotSame(0, $status);
            self::assertStringContainsString('empty temporary directory', implode("\n", $output));
            self::assertSame('untouched', file_get_contents($workdir . '/keep'));
        } finally {
            unlink($workdir . '/keep');
            rmdir($workdir);
        }
    }
}
