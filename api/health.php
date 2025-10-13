<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
// If Composer autoload exists, load it and attempt to read .env from project root
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(\Dotenv\Dotenv::class)) {
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

// Resolve env vars in the same way as includes/db.php
$resolve = static function (string $key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $value;
};

// Do not expose sensitive values like passwords
$pw = $resolve('DB_PASSWORD', null);
$env = [
    'DB_HOST' => $resolve('DB_HOST'),
    'DB_PORT' => $resolve('DB_PORT'),
    'DB_NAME' => $resolve('DB_NAME'),
    'DB_USER' => $resolve('DB_USER'),
    'DB_PASSWORD_set' => ($pw !== null && $pw !== ''),
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
