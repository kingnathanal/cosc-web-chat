<?php
declare(strict_types=1);

/**
 * Returns a shared PDO connection using environment-provided credentials.
 *
 * Expected environment variables (with sensible defaults):
 * - DB_HOST (default: 127.0.0.1)
 * - DB_PORT (default: 3306)
 * - DB_NAME (default: 436db)
 * - DB_USER (default: root)
 * - DB_PASSWORD (default: empty string)
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = '52.23.182.140';
    $port = '3306';
    $dbname = '436db';
    $user = '436_mysql_user';
    $password = '';

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
