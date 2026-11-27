<?php
declare(strict_types=1);

final class MariaDbPlanProbe
{
    public static function detect(PDO $pdo): array
    {
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        foreach (['ANALYZE FORMAT=JSON', 'EXPLAIN FORMAT=JSON', 'EXPLAIN'] as $command) {
            try {
                $stmt = $pdo->prepare("$command SELECT ?");
                $stmt->execute([1]);
                $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $json = $command !== 'EXPLAIN' ? json_decode((string)reset($raw[0]), true, 512, JSON_THROW_ON_ERROR) : null;
                $analyze = $command === 'ANALYZE FORMAT=JSON';
                if ($analyze && !str_contains(json_encode($json, JSON_THROW_ON_ERROR), '"r_total_time_ms"')) {
                    continue;
                }
                return ['server_version' => $version, 'plan_method' => $command, 'supports_analyze' => $analyze, 'command' => $command];
            } catch (PDOException | JsonException $error) {
                continue;
            }
        }
        throw new RuntimeException('No supported MariaDB plan command');
    }

    public static function explain(PDO $pdo, string $sql, array $params, array $options): array
    {
        $command = $options['command'];
        if (!in_array($command, ['ANALYZE FORMAT=JSON', 'EXPLAIN FORMAT=JSON', 'EXPLAIN'], true)
            || !preg_match('/^SELECT\s/i', $sql)) {
            throw new InvalidArgumentException('Expected supported read-only plan command');
        }
        $stmt = $pdo->prepare("$command $sql");
        $stmt->execute($params);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($command === 'EXPLAIN') {
            return ['raw' => $raw] + self::parseStandard($raw);
        }
        $json = json_decode((string)reset($raw[0]), true, 512, JSON_THROW_ON_ERROR);
        return ['raw' => $json] + self::parseJson($json, $command === 'ANALYZE FORMAT=JSON');
    }

    public static function parseJson(array $plan, bool $actual): array
    {
        $tables = [];
        $temporary = false;
        $filesort = false;
        $visit = static function (array $node) use (&$visit, &$tables, &$temporary, &$filesort, $actual): void {
            foreach ($node as $name => $value) {
                $temporary = $temporary || $name === 'temporary_table';
                $filesort = $filesort || $name === 'filesort';
                if (!is_array($value)) {
                    continue;
                }
                if ($name === 'table' && isset($value['table_name'])) {
                    $rows = $actual && is_numeric($value['r_rows'] ?? null) ? (float)$value['r_rows'] : null;
                    $loops = $actual && is_numeric($value['r_loops'] ?? null) ? (int)$value['r_loops'] : null;
                    $tables[] = [
                        'table' => $value['table_name'], 'access_type' => $value['access_type'] ?? null,
                        'key' => $value['key'] ?? null, 'possible_keys' => $value['possible_keys'] ?? [],
                        'estimated_rows' => $value['rows'] ?? null,
                        'actual_rows_per_loop' => $rows, 'actual_loops' => $loops,
                        'actual_rows_total' => $rows !== null && $loops !== null ? $rows * $loops : null,
                    ];
                }
                $visit($value);
            }
        };
        $visit($plan);
        return ['tables' => $tables, 'temporary' => $temporary, 'filesort' => $filesort];
    }

    public static function parseStandard(array $raw): array
    {
        $tables = [];
        $temporary = false;
        $filesort = false;
        foreach ($raw as $row) {
            $extra = $row['Extra'] ?? '';
            $temporary = $temporary || str_contains($extra, 'Using temporary');
            $filesort = $filesort || str_contains($extra, 'Using filesort');
            $tables[] = [
                'table' => $row['table'] ?? null, 'access_type' => $row['type'] ?? null,
                'key' => $row['key'] ?? null, 'possible_keys' => empty($row['possible_keys']) ? [] : explode(',', $row['possible_keys']),
                'estimated_rows' => isset($row['rows']) ? (int)$row['rows'] : null,
                'actual_rows_per_loop' => null, 'actual_loops' => null, 'actual_rows_total' => null,
            ];
        }
        return ['tables' => $tables, 'temporary' => $temporary, 'filesort' => $filesort];
    }
}
