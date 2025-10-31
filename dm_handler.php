<?php
declare(strict_types=1);

// DM handling module (root copy). This is a duplicate of the includes
// version moved to project root so the application can operate without
// an `includes/` directory.

function handle_direct_message(int $clientId, array $data, ?int $requestId = null): void
{
    global $clients, $clientsByUser, $pdo;

    if (!isset($clients[$clientId]) || empty($clients[$clientId]['handshake'])) {
        sendJson($clientId, ['type' => 'error', 'action' => 'dm', 'message' => 'Unauthorized', 'requestId' => $requestId]);
        return;
    }

    $client = $clients[$clientId];
    $roomId = isset($data['roomId']) ? (int)$data['roomId'] : 0;
    $body = isset($data['body']) ? trim((string)$data['body']) : '';
    $recipientsRaw = $data['recipients'] ?? [];

    if ($roomId <= 0 || $roomId !== ($client['currentRoom'] ?? null)) {
        sendJson($clientId, ['type' => 'error', 'action' => 'dm', 'message' => 'Invalid room context', 'requestId' => $requestId]);
        return;
    }
    if ($body === '') {
        sendJson($clientId, ['type' => 'error', 'action' => 'dm', 'message' => 'Message cannot be empty', 'requestId' => $requestId]);
        return;
    }
    if (!is_array($recipientsRaw) || count($recipientsRaw) === 0) {
        sendJson($clientId, ['type' => 'error', 'action' => 'dm', 'message' => 'At least one recipient required', 'requestId' => $requestId]);
        return;
    }

    $normalized = [];
    foreach ($recipientsRaw as $v) {
        $n = trim((string)$v);
        if ($n !== '') $normalized[$n] = true;
    }
    if (empty($normalized)) {
        sendJson($clientId, ['type' => 'error', 'action' => 'dm', 'message' => 'Recipients not provided', 'requestId' => $requestId]);
        return;
    }
    $names = array_keys($normalized);

    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $pdo->prepare("SELECT id, screenName FROM users WHERE screenName IN ($placeholders)");
    $stmt->execute($names);
    $rows = $stmt->fetchAll();
    $targets = [];
    foreach ($rows as $r) {
        $targets[(string)$r['screenName']] = (int)$r['id'];
    }
    $missing = array_values(array_diff($names, array_keys($targets)));
    if (!empty($missing)) {
        sendJson($clientId, ['type' => 'error', 'action' => 'dm', 'message' => 'Unknown recipients', 'missing' => $missing, 'requestId' => $requestId]);
        return;
    }

    $senderId = (int)($client['userId'] ?? 0);
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO direct_messages (sender_id, body) VALUES (:sender, :body)');
        $ins->execute([':sender' => $senderId, ':body' => $body]);
        $dmId = (int)$pdo->lastInsertId();

        $recIns = $pdo->prepare('INSERT INTO direct_message_recipients (dm_id, recipient_id, chatroom_id) VALUES (:dm, :rid, :room)');
        $recipientIds = array_values($targets);
        $recipientIds[] = $senderId;
        $recipientIds = array_values(array_unique($recipientIds));
        foreach ($recipientIds as $rid) {
            $recIns->execute([':dm' => $dmId, ':rid' => $rid, ':room' => $roomId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logMessage('DM insert failed: ' . $e->getMessage());
        sendJson($clientId, ['type' => 'error', 'action' => 'dm', 'message' => 'Unable to send DM', 'requestId' => $requestId]);
        return;
    }

    $stmt = $pdo->prepare('SELECT created_at FROM direct_messages WHERE id = :id');
    $stmt->execute([':id' => $dmId]);
    $createdAt = $stmt->fetchColumn() ?: date('Y-m-d H:i:s');
    $payload = [
        'id' => $dmId,
        'roomId' => $roomId,
        'body' => $body,
        'createdAt' => $createdAt,
        'sender' => $client['screenName'] ?? 'Unknown',
        'isDM' => true,
    ];

    foreach ($recipientIds as $uid) {
        if (empty($clientsByUser[$uid])) continue;
        foreach (array_keys($clientsByUser[$uid]) as $targetClientId) {
            sendJson((int)$targetClientId, ['type' => 'dm', 'roomId' => $roomId, 'message' => $payload]);
        }
    }

    sendJson($clientId, ['type' => 'ack', 'action' => 'dm', 'status' => 'ok', 'roomId' => $roomId, 'dmId' => $dmId, 'requestId' => $requestId]);
}
