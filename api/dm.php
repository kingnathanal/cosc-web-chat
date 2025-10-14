<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$userId = require_authenticated_user();
$pdo = get_db();

// Ensure tables exist (idempotent)
$pdo->exec('CREATE TABLE IF NOT EXISTS direct_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  chatroom_id INT NOT NULL,
  sender_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (chatroom_id) REFERENCES list_of_chatrooms(id),
  FOREIGN KEY (sender_id) REFERENCES users(id)
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS direct_message_recipients (
  dm_id INT NOT NULL,
  recipient_id INT NOT NULL,
  PRIMARY KEY (dm_id, recipient_id),
  FOREIGN KEY (dm_id) REFERENCES direct_messages(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES users(id)
)');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = read_json_input();
    $roomId = isset($input['room_id']) ? (int) $input['room_id'] : 0;
    $body = trim((string)($input['body'] ?? ''));
    $recipients = $input['recipients'] ?? [];

    if ($roomId <= 0) {
        json_response(['error' => 'room_id is required'], 422);
    }
    if ($body === '') {
        json_response(['error' => 'Message body cannot be empty'], 422);
    }
    if (!is_array($recipients) || count($recipients) === 0) {
        json_response(['error' => 'At least one recipient is required'], 422);
    }

    // Validate room exists
    $stmt = $pdo->prepare('SELECT id FROM list_of_chatrooms WHERE id = :id');
    $stmt->execute([':id' => $roomId]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Chat room not found'], 404);
    }

    // Resolve recipients by screenName
    $normalized = [];
    foreach ($recipients as $r) {
        $name = trim((string)$r);
        if ($name !== '') { $normalized[$name] = true; }
    }
    $names = array_keys($normalized);
    if (empty($names)) {
        json_response(['error' => 'At least one valid recipient name is required'], 422);
    }

    $in = str_repeat('?,', count($names));
    $in = rtrim($in, ',');
    $q = $pdo->prepare("SELECT id, screenName FROM users WHERE screenName IN ($in)");
    $q->execute($names);
    $rows = $q->fetchAll();
    $targets = [];
    foreach ($rows as $row) { $targets[(string)$row['screenName']] = (int)$row['id']; }

    $missing = array_values(array_diff($names, array_keys($targets)));
    if (!empty($missing)) {
        json_response(['error' => 'Unknown recipients', 'missing' => $missing], 422);
    }

    // Insert DM and recipients (include sender so they see their own DM)
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO direct_messages (chatroom_id, sender_id, body) VALUES (:room, :sender, :body)');
        $ins->execute([':room' => $roomId, ':sender' => $userId, ':body' => $body]);
        $dmId = (int)$pdo->lastInsertId();

        // Recipients + sender
        $allUserIds = array_values($targets);
        $allUserIds[] = $userId;
        $allUserIds = array_values(array_unique($allUserIds));

        $recIns = $pdo->prepare('INSERT IGNORE INTO direct_message_recipients (dm_id, recipient_id) VALUES (:dm, :rid)');
        foreach ($allUserIds as $rid) {
            $recIns->execute([':dm' => $dmId, ':rid' => $rid]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'Failed to send DM', 'details' => $e->getMessage()], 500);
    }

    json_response(['message' => 'DM sent', 'dm' => ['id' => $dmId]]);
}

if ($method === 'GET') {
    $roomId = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT) ?: 0;
    $after = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT) ?: 0;
    if ($roomId <= 0) {
        json_response(['error' => 'room_id is required'], 422);
    }
    // Fetch DMs for this user in this room
    if ($after > 0) {
        $stmt = $pdo->prepare('SELECT d.id, d.body, d.created_at, u.screenName AS sender
                               FROM direct_messages d
                               JOIN direct_message_recipients r ON r.dm_id = d.id
                               JOIN users u ON u.id = d.sender_id
                               WHERE d.chatroom_id = :room AND r.recipient_id = :me AND d.id > :after
                               ORDER BY d.id ASC');
        $stmt->execute([':room' => $roomId, ':me' => $userId, ':after' => $after]);
    } else {
        $stmt = $pdo->prepare('SELECT d.id, d.body, d.created_at, u.screenName AS sender
                               FROM direct_messages d
                               JOIN direct_message_recipients r ON r.dm_id = d.id
                               JOIN users u ON u.id = d.sender_id
                               WHERE d.chatroom_id = :room AND r.recipient_id = :me
                               ORDER BY d.id DESC
                               LIMIT 50');
        $stmt->execute([':room' => $roomId, ':me' => $userId]);
    }

    $rows = $stmt->fetchAll();
    $dms = [];
    if ($after <= 0) { $rows = array_reverse($rows); }
    foreach ($rows as $row) {
        $dms[] = [
            'id' => (int)$row['id'],
            'body' => (string)$row['body'],
            'createdAt' => (string)$row['created_at'],
            'sender' => (string)$row['sender'],
            'isDM' => true,
        ];
    }

    json_response(['dms' => $dms, 'roomId' => (int)$roomId]);
}

json_response(['error' => 'Method not allowed'], 405);

