<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// Do not expose sensitive values like passwords
$env = [
    'DB_HOST' => getenv('DB_HOST') !== false ? getenv('DB_HOST') : null,
    'DB_PORT' => getenv('DB_PORT') !== false ? getenv('DB_PORT') : null,
    'DB_NAME' => getenv('DB_NAME') !== false ? getenv('DB_NAME') : null,
    'DB_USER' => getenv('DB_USER') !== false ? getenv('DB_USER') : null,
    'DB_PASSWORD_set' => getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== ''
];

$result = [
    'env' => $env,
    'connection' => null,
];

try {
    $pdo = get_db();
    // Perform a trivial query to ensure the connection is usable
    $pdo->query('SELECT 1');
    $result['connection'] = 'ok';
    json_response($result, 200);
} catch (Throwable $e) {
    $result['connection'] = [
        'status' => 'error',
        'message' => $e->getMessage(),
    ];
    json_response($result, 500);
}

