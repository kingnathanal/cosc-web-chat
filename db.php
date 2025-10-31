<?php
declare(strict_types=1);

// Load Composer autoload and optional .env if available (project root)
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(\Dotenv\Dotenv::class)) {
        \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
    }
}

/**
 * Returns a shared PDO connection using environment-provided credentials.
 *
 * Required environment variables (no implicit defaults):
 * - DB_HOST
 * - DB_PORT
 * - DB_NAME
 * - DB_USER
 * - DB_PASSWORD
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Resolve required credentials from environment; fail fast if missing
    $requireEnv = static function (string $key): string {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        // Treat empty-string as missing for critical credentials
        if ($value === null || (is_string($value) && trim($value) === '')) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Database configuration missing',
                'details' => sprintf('Required environment variable %s is not set', $key),
            ]);
            exit;
        }
        return (string) $value;
    };

    $host = $requireEnv('DB_HOST');
    $port = $requireEnv('DB_PORT');
    $dbname = $requireEnv('DB_NAME');
    $user = $requireEnv('DB_USER');
    $password = $requireEnv('DB_PASSWORD');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $password, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Database connection failed',
            'details' => $e->getMessage(),
        ]);
        exit;
    }

    return $pdo;
}
