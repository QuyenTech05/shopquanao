<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(getenv('KNOWLEDGE_TEST_HTTP_LOG'), $path . PHP_EOL, FILE_APPEND);
header('Content-Type: application/json');

if (str_ends_with($path, '/points/search')) {
    $payload = static fn(string $title, string $content): array => [
        'title' => $title,
        'content' => $content,
        'category' => 'general',
        'source' => 'fixture',
        'doc_key' => sha1('fixture|' . $title . '|' . $content),
    ];
    echo json_encode(['result' => [
        ['score' => 0.9, 'payload' => $payload('Vector', 'Unrelated content')],
        ['score' => 0.8, 'payload' => $payload('Shipping', 'shipping policy')],
    ]]);
} elseif ($path === '/rerank' || $path === '/invalid/rerank' || $path === '/auth/rerank') {
    if ($path === '/auth/rerank' && ($_SERVER['HTTP_X_RAG_ML_TOKEN'] ?? '') !== 'fixture-token') {
        http_response_code(401);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $indices = array_reverse(array_keys($body['texts']));
    if ($path === '/invalid/rerank') {
        $indices = array_fill(0, count($indices), 0);
    }
    echo json_encode(['sorted_indices' => $indices, 'scores' => array_keys($indices), 'model' => 'fixture']);
} else {
    http_response_code(503);
}
