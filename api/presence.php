<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$userId = require_authenticated_user();
$input = json_decode(file_get_contents('php://input') ?: '[]', true);

$action = isset($input['action']) ? (string) $input['action'] : '';
$roomId = isset($input['room_id']) ? (int) $input['room_id'] : 0;

if ($roomId <= 0 || ($action !== 'join' && $action !== 'leave')) {
    json_response(['error' => 'Invalid payload'], 422);
}

$pdo = get_db();

// Verify room exists
$stmt = $pdo->prepare('SELECT id FROM list_of_chatrooms WHERE id = :id');
$stmt->execute([':id' => $roomId]);
if (!$stmt->fetchColumn()) {
    json_response(['error' => 'Chat room not found'], 404);
}

$screenName = $_SESSION['screen_name'] ?? null;
if (!is_string($screenName) || $screenName === '') {
    // Fallback: fetch from DB
    $stmt = $pdo->prepare('SELECT screenName FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    $screenName = $row['screenName'] ?? 'Someone';
}

if ($action === 'join') {
    // Check if already present
    $existsStmt = $pdo->prepare('SELECT id FROM current_chatroom_occupants WHERE chatroom_id = :room AND user_id = :user LIMIT 1');
    $existsStmt->execute([':room' => $roomId, ':user' => $userId]);
    $alreadyThere = (bool) $existsStmt->fetchColumn();

    if (!$alreadyThere) {
        // Insert presence (socket_id placeholder 0)
        $ins = $pdo->prepare('INSERT INTO current_chatroom_occupants (chatroom_id, user_id, socket_id) VALUES (:room, :user, 0)');
        $ins->execute([':room' => $roomId, ':user' => $userId]);

        // Broadcast join message
        $msg = $pdo->prepare('INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)');
        $msg->execute([
            ':room' => $roomId,
            ':user' => $userId,
            ':body' => sprintf('%s joined the chat', $screenName),
        ]);
    }

    json_response(['ok' => true]);
}

if ($action === 'leave') {
    // Remove presence if exists
    $del = $pdo->prepare('DELETE FROM current_chatroom_occupants WHERE chatroom_id = :room AND user_id = :user');
    $del->execute([':room' => $roomId, ':user' => $userId]);

    // Broadcast leave message
    $msg = $pdo->prepare('INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)');
    $msg->execute([
        ':room' => $roomId,
        ':user' => $userId,
        ':body' => sprintf('%s left the chat', $screenName),
    ]);

    json_response(['ok' => true]);
}

