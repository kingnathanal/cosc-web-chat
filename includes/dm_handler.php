<?php
declare(strict_types=1);

// DM handling module — separated from the main server loop so teams can
// implement alternate styles. This function expects the server to include
// this file and call handle_direct_message($clientId, $data, $requestId).

/**
 * Handle a direct-message payload coming from the WebSocket client.
 * Uses globals from the server ("$clients", "$clientsByUser", "$pdo").
 *
 * @param int $clientId
 * @param array $data
 * @param int|null $requestId
 */
function handle_direct_message(int $clientId, array $data, ?int $requestId = null): void
{
    global $clients, $clientsByUser, $pdo;

    // Defensive checks: ensure client exists and has joined a room.
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

    // Normalize and resolve recipients by screenName
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

    // Lookup users
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

    // Persist DM and recipients in a transaction
    $senderId = (int)($client['userId'] ?? 0);
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO direct_messages (sender_id, body) VALUES (:sender, :body)');
        $ins->execute([':sender' => $senderId, ':body' => $body]);
        $dmId = (int)$pdo->lastInsertId();

        $recIns = $pdo->prepare('INSERT INTO direct_message_recipients (dm_id, recipient_id, chatroom_id) VALUES (:dm, :rid, :room)');
        $recipientIds = array_values($targets);
        // include sender so they see their own DM
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

    // Build payload and push to online recipients
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

    // Notify connected clients for each recipient id
    foreach ($recipientIds as $uid) {
        if (empty($clientsByUser[$uid])) continue;
        foreach (array_keys($clientsByUser[$uid]) as $targetClientId) {
            sendJson((int)$targetClientId, ['type' => 'dm', 'roomId' => $roomId, 'message' => $payload]);
        }
    }

    sendJson($clientId, ['type' => 'ack', 'action' => 'dm', 'status' => 'ok', 'roomId' => $roomId, 'dmId' => $dmId, 'requestId' => $requestId]);
}
