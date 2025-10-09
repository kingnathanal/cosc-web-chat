<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$userId = require_authenticated_user();

$pdo = get_db();

if ($method === 'GET') {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM list_of_chatrooms')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO list_of_chatrooms (chatroomName, key_hash, creator_user_id)
             VALUES (:name, NULL, :creator)'
        );
        $stmt->execute([
            ':name' => 'General Chat',
            ':creator' => $userId,
        ]);
    }

    $stmt = $pdo->query(
        'SELECT c.id,
                c.chatroomName AS name,
                c.key_hash IS NULL AS is_open,
                COUNT(o.id) AS occupantCount,
                c.created_at
         FROM list_of_chatrooms c
         LEFT JOIN current_chatroom_occupants o ON o.chatroom_id = c.id
         GROUP BY c.id
         ORDER BY c.chatroomName ASC'
    );

    $rooms = [];
    foreach ($stmt->fetchAll() as $row) {
        $rooms[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'status' => $row['is_open'] ? 'open' : 'locked',
            'occupantCount' => (int) $row['occupantCount'],
            'createdAt' => $row['created_at'],
        ];
    }

    json_response(['rooms' => $rooms]);
}

if ($method === 'POST') {
    $input = read_json_input();
    $name = trim($input['name'] ?? '');
    $passphrase = trim($input['passphrase'] ?? '');

    if ($name === '') {
        json_response(['error' => 'Room name is required'], 422);
    }

    $keyHash = $passphrase !== '' ? password_hash($passphrase, PASSWORD_DEFAULT) : null;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO list_of_chatrooms (chatroomName, key_hash, creator_user_id) VALUES (:name, :key_hash, :creator)'
        );
        $stmt->execute([
            ':name' => $name,
            ':key_hash' => $keyHash,
            ':creator' => $userId,
        ]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            json_response(['error' => 'A room with that name already exists'], 409);
        }

        json_response(['error' => 'Failed to create room', 'details' => $e->getMessage()], 500);
    }

    json_response([
        'message' => 'Room created',
        'room' => [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'status' => $keyHash === null ? 'open' : 'locked',
            'occupantCount' => 0,
        ],
    ], 201);
}

json_response(['error' => 'Method not allowed'], 405);
