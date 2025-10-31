<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$userId = require_authenticated_user();
$input = json_decode(file_get_contents('php://input') ?: '[]', true);

$roomId = isset($input['room_id']) ? (int) $input['room_id'] : 0;
$passphrase = isset($input['passphrase']) ? (string) $input['passphrase'] : '';

if ($roomId <= 0) {
    json_response(['error' => 'room_id is required'], 422);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id, chatroomName, key_hash FROM list_of_chatrooms WHERE id = :id');
$stmt->execute([':id' => $roomId]);
$room = $stmt->fetch();

if (!$room) {
    json_response(['error' => 'Chat room not found'], 404);
}

$isOpen = $room['key_hash'] === null;

if ($isOpen) {
    json_response(['allowed' => true, 'room' => ['id' => (int)$room['id'], 'name' => $room['chatroomName']]]);
}

if ($passphrase === '' || !password_verify($passphrase, (string) $room['key_hash'])) {
    json_response(['error' => 'Incorrect password'], 401);
}

json_response(['allowed' => true, 'room' => ['id' => (int)$room['id'], 'name' => $room['chatroomName']]]);
