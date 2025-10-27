<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$userId = require_authenticated_user();

try {
    $token = bin2hex(random_bytes(32));
} catch (Throwable $e) {
    json_response(['error' => 'Failed to generate token'], 500);
}

$pdo = get_db();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO sockets (user_id, socket_token) VALUES (:user, :token)'
    );
    $stmt->execute([
        ':user' => $userId,
        ':token' => $token,
    ]);
} catch (PDOException $e) {
    json_response(['error' => 'Unable to persist socket token'], 500);
}

json_response(['token' => $token], 201);

