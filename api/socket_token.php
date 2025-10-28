<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$userId = require_authenticated_user();
$pdo = get_db();

// Invalidate any lingering tokens for this user so we only track the most recent connection.
$stmt = $pdo->prepare('UPDATE sockets SET disconnected_at = NOW() WHERE user_id = :user AND disconnected_at IS NULL');
$stmt->execute([':user' => $userId]);

try {
    $token = bin2hex(random_bytes(24));
} catch (Throwable $e) {
    json_response(['error' => 'Failed to generate socket token'], 500);
}

$stmt = $pdo->prepare(
    'INSERT INTO sockets (user_id, socket_token, connected_at, disconnected_at) VALUES (:user, :token, NOW(), NULL)'
);
$stmt->execute([
    ':user' => $userId,
    ':token' => $token,
]);
$socketId = (int) $pdo->lastInsertId();

// Allow deployment to override the endpoint/port; fall back to sensible defaults.
$endpoint = getenv('WS_ENDPOINT');
if (!is_string($endpoint) || trim($endpoint) === '') {
    $hostHeader = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostParts = explode(':', $hostHeader);
    $host = $hostParts[0] ?? 'localhost';
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
    $scheme = $isHttps ? 'wss' : 'ws';
    $port = getenv('WS_PORT');
    if (!is_string($port) || trim($port) === '') {
        $port = '8090';
    }
    $endpoint = sprintf('%s://%s:%s', $scheme, $host, $port);
}

json_response([
    'token' => $token,
    'socketId' => $socketId,
    'endpoint' => rtrim($endpoint, '/'),
]);
