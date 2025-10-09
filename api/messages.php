<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$userId = require_authenticated_user();
$pdo = get_db();

if ($method === 'GET') {
    $roomId = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT);
    if (!$roomId) {
        json_response(['error' => 'room_id is required'], 422);
    }

    $afterId = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT);

    if ($afterId) {
        $stmt = $pdo->prepare(
            'SELECT m.id, m.body, m.created_at, u.screenName
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.chatroom_id = :room AND m.id > :after
             ORDER BY m.id ASC'
        );
        $stmt->execute([
            ':room' => $roomId,
            ':after' => $afterId,
        ]);
        $messages = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            'SELECT m.id, m.body, m.created_at, u.screenName
             FROM messages m
             JOIN users u ON u.id = m.user_id
             WHERE m.chatroom_id = :room
             ORDER BY m.id DESC
             LIMIT 50'
        );
        $stmt->execute([':room' => $roomId]);
        $messages = array_reverse($stmt->fetchAll());
    }

    $payload = [];
    foreach ($messages as $message) {
        $payload[] = [
            'id' => (int) $message['id'],
            'body' => $message['body'],
            'createdAt' => $message['created_at'],
            'sender' => $message['screenName'],
        ];
    }

    json_response([
        'messages' => $payload,
        'roomId' => (int) $roomId,
    ]);
}

if ($method === 'POST') {
    $input = read_json_input();
    $roomId = isset($input['room_id']) ? (int) $input['room_id'] : 0;
    $body = trim($input['body'] ?? '');

    if ($roomId <= 0) {
        json_response(['error' => 'room_id is required'], 422);
    }

    if ($body === '') {
        json_response(['error' => 'Message body cannot be empty'], 422);
    }

    // Ensure the chatroom exists before inserting.
    $stmt = $pdo->prepare('SELECT id FROM list_of_chatrooms WHERE id = :id');
    $stmt->execute([':id' => $roomId]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Chat room not found'], 404);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)'
    );
    $stmt->execute([
        ':room' => $roomId,
        ':user' => $userId,
        ':body' => $body,
    ]);

    json_response([
        'message' => 'Message sent',
        'payload' => [
            'id' => (int) $pdo->lastInsertId(),
            'body' => $body,
            'createdAt' => date('Y-m-d H:i:s'),
        ],
    ], 201);
}

json_response(['error' => 'Method not allowed'], 405);
