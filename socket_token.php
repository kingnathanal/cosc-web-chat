<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// Returns a socket token for the authenticated user. If an active token
// already exists (not disconnected), return it; otherwise create a new one.

$userId = require_authenticated_user();
$pdo = get_db();

try {
    $stmt = $pdo->prepare('SELECT socket_token FROM sockets WHERE user_id = :uid AND disconnected_at IS NULL LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    json_response(['error' => 'db_error', 'details' => $e->getMessage()], 500);
}

if ($row && !empty($row['socket_token'])) {
    json_response(['token' => (string)$row['socket_token']]);
}

// Generate a secure random token
try {
    $token = bin2hex(random_bytes(24));
} catch (Throwable $_) {
    // Fallback to less-preferred uniqid
    $token = bin2hex(openssl_random_pseudo_bytes(24) ?: uniqid('', true));
}

try {
    $ins = $pdo->prepare('INSERT INTO sockets (user_id, socket_token) VALUES (:uid, :token)');
    $ins->execute([':uid' => $userId, ':token' => $token]);
} catch (Throwable $e) {
    json_response(['error' => 'db_error', 'details' => $e->getMessage()], 500);
}

json_response(['token' => $token], 201);
