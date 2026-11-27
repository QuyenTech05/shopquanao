<?php
declare(strict_types=1);

require_once __DIR__ . '/sql_queries.php';
require_once __DIR__ . '/mariadb_plan_probe.php';

final class BenchmarkOptions
{
    public function __construct(public int $runs, public int $warmups, public int $seed)
    {
        if ($runs < 1 || $warmups < 0 || $seed < 1) {
            throw new InvalidArgumentException('runs must be positive, warmups non-negative, seed positive');
        }
    }
}

final class QueryDefinition
{
    public array $definition;

    public function __construct(public string $id)
    {
        $all = SqlQueryCatalog::all();
        if (!isset($all[$id])) {
            throw new InvalidArgumentException("Unknown query ID: $id");
        }
        $this->definition = $all[$id];
    }
}

final class SqlBenchmarkRunner
{
    public static function percentile(array $samples, float $p): float
    {
        if ($samples === [] || $p < 0 || $p > 1) {
            throw new InvalidArgumentException('percentile requires samples and p in [0,1]');
        }
        sort($samples, SORT_NUMERIC);
        $index = (int)ceil($p * count($samples)) - 1;
        return (float)$samples[max(0, min($index, count($samples) - 1))];
    }

    public static function order(int $offset): array
    {
        return $offset % 2 === 0 ? ['indexed', 'baseline'] : ['baseline', 'indexed'];
    }

    public static function counterDelta(array $before, array $after, array $unavailable = []): array
    {
        $result = [];
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after), array_keys($unavailable))));
        foreach ($keys as $key) {
            if (array_key_exists($key, $unavailable) && $unavailable[$key] === null) {
                $result[$key] = null;
                continue;
            }
            $result[$key] = isset($before[$key], $after[$key]) ? (int)$after[$key] - (int)$before[$key] : null;
        }
        return $result;
    }

    public static function runVariant(PDO $pdo, QueryDefinition $query, BenchmarkOptions $options, array $planOptions): array
    {
        $sql = $query->definition['sql'];
        $params = $query->definition['params'];
        $plan = MariaDbPlanProbe::explain($pdo, $sql, $params, $planOptions);
        $samples = [];
        for ($i = 0; $i < $options->warmups; $i++) {
            self::execute($pdo, $sql, $params);
        }
        for ($i = 0; $i < $options->runs; $i++) {
            $start = hrtime(true);
            self::execute($pdo, $sql, $params);
            $samples[] = (hrtime(true) - $start) / 1_000_000;
        }
        return [
            'query_id' => $query->id,
            'samples_ms' => $samples,
            'p50_ms' => self::percentile($samples, .50),
            'p95_ms' => self::percentile($samples, .95),
            'p99_ms' => self::percentile($samples, .99),
            'plan' => $plan,
            'warmups' => $options->warmups,
            'runs' => $options->runs,
            'rows_examined' => null,
            'rows_sent' => null,
            'counters_available' => false,
        ];
    }

    private static function execute(PDO $pdo, string $sql, array $params): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['indexed-dsn:', 'baseline-dsn:', 'user:', 'password:', 'runs::', 'warmups::', 'seed::', 'output:']);
    foreach (['indexed-dsn', 'baseline-dsn', 'user', 'password', 'output'] as $key) {
        if (!isset($options[$key])) throw new InvalidArgumentException("Required: --$key");
    }
    $benchmarkOptions = new BenchmarkOptions((int)($options['runs'] ?? 30), (int)($options['warmups'] ?? 5), (int)($options['seed'] ?? 20261127));
    $results = ['metadata' => ['seed' => $benchmarkOptions->seed, 'runs' => $benchmarkOptions->runs, 'warmups' => $benchmarkOptions->warmups], 'queries' => []];
    foreach (['indexed', 'baseline'] as $variant) {
        $pdo = new PDO($options[$variant . '-dsn'], $options['user'], $options['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $probe = MariaDbPlanProbe::detect($pdo);
        $results['metadata']['server_version'] = $probe['server_version'];
        foreach (array_keys(SqlQueryCatalog::all()) as $id) {
            $measurement = SqlBenchmarkRunner::runVariant($pdo, new QueryDefinition($id), $benchmarkOptions, ['command' => $probe['command']]);
            $results['queries'][$id][$variant] = $measurement;
        }
    }
    $output = rtrim($options['output'], '/');
    if (!is_dir($output) && !mkdir($output, 0770, true) && !is_dir($output)) throw new RuntimeException('Cannot create output directory');
    file_put_contents($output . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    $fp = fopen($output . '/results.csv', 'wb');
    fputcsv($fp, ['query_id','variant','p50_ms','p95_ms','p99_ms','chosen_index','access_type','estimated_rows','actual_rows_total']);
    foreach ($results['queries'] as $id => $variants) foreach ($variants as $variant => $row) {
        $table = $row['plan']['tables'][0] ?? [];
        fputcsv($fp, [$id,$variant,$row['p50_ms'],$row['p95_ms'],$row['p99_ms'],$table['key'] ?? null,$table['access_type'] ?? null,$table['estimated_rows'] ?? null,$table['actual_rows_total'] ?? null]);
    }
    fclose($fp);
}
