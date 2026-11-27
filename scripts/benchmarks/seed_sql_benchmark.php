<?php
declare(strict_types=1);

final class BenchmarkSeed
{
    private const COUNTS = [
        'users' => 5000, 'products' => 50000, 'product_variants' => 200000,
        'product_sizes' => 100000, 'orders' => 100000, 'order_items' => 200000,
        'cart' => 15000, 'chat_sessions' => 25000, 'chat_messages' => 250000,
        'chat_session_memory' => 25000, 'support_tickets' => 50000, 'faqs' => 10000,
    ];
    private const COLUMNS = [
        'users' => 'id,username,email,password,created_at',
        'products' => 'id,category_id,name,price,stock,description,image',
        'product_variants' => 'id,product_id,variant_key,color_id,size,price,stock,is_active,created_at,updated_at',
        'product_sizes' => 'id,product_id,size_name',
        'orders' => 'id,user_id,total_price,status,created_at',
        'order_items' => 'id,order_id,product_id,quantity,price',
        'cart' => 'id,user_id,product_id,quantity,size,created_at',
        'chat_sessions' => 'id,user_id,session_token,status,created_at,updated_at',
        'chat_messages' => 'id,session_id,role,message,metadata,created_at',
        'chat_session_memory' => 'session_id,summary,slots,created_at,updated_at',
        'support_tickets' => 'id,user_id,order_id,type,summary,status,chat_session_id,created_at,updated_at',
        'faqs' => 'id,question,answer,category,priority',
    ];

    public function __construct(private string $scale, private int $seed)
    {
        if ($scale !== 'large' || $seed < 1 || $seed > 2147483647) {
            throw new InvalidArgumentException('Expected --scale=large and positive integer seed');
        }
    }

    public function manifest(): array
    {
        return ['scale' => $this->scale, 'seed' => $this->seed, 'synthetic' => true, 'counts' => self::COUNTS];
    }

    public function rows(string $table): Generator
    {
        if (!isset(self::COUNTS[$table])) {
            throw new InvalidArgumentException('Unknown fixture table');
        }
        $random = new Random\Randomizer(new Random\Engine\Mt19937($this->seed));
        for ($i = 1; $i <= self::COUNTS[$table]; $i++) {
            $date = gmdate('Y-m-d H:i:s', 1767225600 + $i);
            $owner = 1 + ($i - 1) % self::COUNTS['users'];
            $price = 100000 + $i; // Unique prices ensure reproducible ORDER BY ... LIMIT.
            $product = 1 + ($i - 1) % self::COUNTS['products'];
            $variantProduct = 1 + intdiv($i - 1, 4);
            $variant = ($i - 1) % 4;
            $order = 1 + intdiv($i - 1, 2);
            $session = 1 + ($i - 1) % self::COUNTS['chat_sessions'];
            yield match ($table) {
                'users' => [$i, "bench-user-$i", "bench-$i@example.invalid", 'disabled-synthetic-account', $date],
                'products' => [$i, 1 + ($i - 1) % 5, "Synthetic product $i", $price, $random->getInt(0, 50), 'Synthetic description', null],
                'product_variants' => [$i, $variantProduct, ['black|M', 'white|L', 'legacy|black|S', 'black|XL'][$variant], [1, 2, 1, 1][$variant], ['M', 'L', 'S', 'XL'][$variant], 100000 + $variantProduct, $random->getInt(0, 20), $variant === 3 ? 0 : 1, $date, $date],
                'product_sizes' => [$i, 1 + intdiv($i - 1, 2), ['M', 'L'][($i - 1) % 2]],
                'orders' => [$i, $owner, 200000 + $i, ['Đã hoàn thành', 'Pending', 'Đang giao'][intdiv($i - 1, 5000) % 3], $date],
                'order_items' => [$i, $order, $product, 1, 100000 + $product],
                'cart' => [$i, $owner, $product, $random->getInt(1, 4), 'M', $date],
                'chat_sessions' => [$i, $owner, "synthetic-session-$i", intdiv($i - 1, 5000) % 3 === 2 ? 'closed' : 'active', $date, $date],
                'chat_messages' => [$i, $session, intdiv($i - 1, 25000) % 2 ? 'bot' : 'user', "Synthetic message $i", null, $date],
                'chat_session_memory' => [$i, "Synthetic summary $i", '{}', $date, $date],
                'support_tickets' => [$i, $owner, $i, 'order', "Synthetic ticket $i", ['moi', 'dang_xu_ly', 'da_dong'][intdiv($i - 1, 5000) % 3], $session, $date, $date],
                'faqs' => [$i, "Synthetic shipping question $i", "Synthetic shipping answer $i", $i % 10 === 0 ? 'shipping' : 'general-' . ($i % 9), $i % 20],
            };
        }
    }

    public function insert(PDO $pdo): void
    {
        foreach (self::COUNTS as $table => $count) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 0) {
                throw new RuntimeException("Fixture table is not empty: $table");
            }
        }
        $pdo->beginTransaction();
        try {
            foreach (self::COLUMNS as $table => $columns) {
                $batch = [];
                foreach ($this->rows($table) as $row) {
                    $batch[] = $row;
                    if (count($batch) === 500) {
                        self::insertBatch($pdo, $table, $columns, $batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    self::insertBatch($pdo, $table, $columns, $batch);
                }
                if ((int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== self::COUNTS[$table]) {
                    throw new RuntimeException("Fixture count mismatch: $table");
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    private static function insertBatch(PDO $pdo, string $table, string $columns, array $batch): void
    {
        $marks = '(' . implode(',', array_fill(0, count($batch[0]), '?')) . ')';
        $stmt = $pdo->prepare("INSERT INTO `$table` ($columns) VALUES " . implode(',', array_fill(0, count($batch), $marks)));
        $stmt->execute(array_merge(...$batch));
    }
}

function benchmarkConnection(string $dsn, string $user, string $password): PDO
{
    if (!preg_match('/^mysql:host=127\.0\.0\.1;port=3306;dbname=sqlbench_[a-f0-9]{12}_(indexed|baseline);charset=utf8mb4$/D', $dsn)) {
        throw new InvalidArgumentException('Only isolated loopback benchmark DSNs are accepted');
    }
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    if ($pdo->query("SELECT marker FROM benchmark_fixture_marker")->fetchColumn() !== 'disposable-synthetic-sql-benchmark-v1') {
        throw new RuntimeException('Disposable benchmark marker missing');
    }
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    return $pdo;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['scale:', 'seed:', 'output:']);
    $seed = filter_var($options['seed'] ?? false, FILTER_VALIDATE_INT);
    if ($seed === false || !isset($options['output'])) {
        throw new InvalidArgumentException('Required: --scale=large --seed=20261127 --output=directory');
    }
    $plan = new BenchmarkSeed($options['scale'] ?? '', $seed);
    $pdo = benchmarkConnection(getenv('BENCHMARK_DSN') ?: '', getenv('BENCHMARK_USER') ?: '', getenv('BENCHMARK_PASSWORD') ?: '');
    $plan->insert($pdo);
    $file = rtrim($options['output'], '/') . '/seed-manifest.json';
    if (file_put_contents($file, json_encode($plan->manifest(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
        throw new RuntimeException('Could not write seed manifest');
    }
}
