<?php
declare(strict_types=1);

// Simple WebSocket server that plugs into the existing Box Chat database/session state.
// Run with: php ws-server.php


require_once __DIR__ . '/includes/db.php';

session_name(getenv('PHP_SESSION_NAME') ?: 'PHPSESSID');

const WS_DEFAULT_PORT = 8090;
const WS_BACKLOG = 128;
const WS_BUFFER_SIZE = 8192;
const WS_PING_INTERVAL = 30;

$port = (int) (getenv('WS_PORT') ?: WS_DEFAULT_PORT);
$host = getenv('WS_HOST') ?: '0.0.0.0';

set_time_limit(0);
error_reporting(E_ALL);

initializeStorage();

// Shared state
$serverSocket = createServerSocket($host, $port);
$clientSockets = [];
$clientMeta = [];
$rooms = []; // roomId => [clientId => true]

echo sprintf("WebSocket server listening on %s:%d\n", $host, $port);

while (true) {
    $readSockets = $clientSockets;
    $readSockets[] = $serverSocket;
    $write = $except = [];

    if (@socket_select($readSockets, $write, $except, WS_PING_INTERVAL) === false) {
        echo "socket_select failed: " . socket_strerror(socket_last_error()) . PHP_EOL;
        break;
    }

    // Accept new connections
    if (in_array($serverSocket, $readSockets, true)) {
        $readSockets = array_diff($readSockets, [$serverSocket]);

        $clientSocket = @socket_accept($serverSocket);
        if ($clientSocket === false) {
            echo "Failed to accept client: " . socket_strerror(socket_last_error($serverSocket)) . PHP_EOL;
            continue;
        }

        $handshake = performHandshake($clientSocket);
        if ($handshake === null) {
            @socket_close($clientSocket);
            continue;
        }

        $clientId = get_resource_id($clientSocket);
        socket_getpeername($clientSocket, $peerAddress, $peerPort);
        $clientSockets[$clientId] = $clientSocket;
        $clientMeta[$clientId] = [
            'socket' => $clientSocket,
            'user_id' => $handshake['user_id'],
            'username' => $handshake['username'],
            'screen_name' => $handshake['screen_name'],
            'session_id' => $handshake['session_id'],
            'rooms' => [],
            'last_pong' => time(),
            'peer' => $peerAddress . ':' . $peerPort,
        ];

        echo sprintf(
            "Client connected: %s (user_id=%d, screen=%s)\n",
            $clientMeta[$clientId]['peer'],
            $clientMeta[$clientId]['user_id'],
            $clientMeta[$clientId]['screen_name']
        );

        sendEnvelope($clientSocket, [
            'type' => 'welcome',
            'user' => [
                'id' => $clientMeta[$clientId]['user_id'],
                'username' => $clientMeta[$clientId]['username'],
                'screenName' => $clientMeta[$clientId]['screen_name'],
            ],
        ]);
    }

    // Handle heartbeats
    $now = time();
    foreach ($clientMeta as $clientId => $meta) {
        if (($now - $meta['last_pong']) > (WS_PING_INTERVAL * 2)) {
            echo "Client timed out: {$meta['peer']}\n";
            disconnectClient($clientId, 'timeout');
        } else {
            sendEnvelope($meta['socket'], ['type' => 'ping']);
        }
    }

    // Read messages
    foreach ($readSockets as $socket) {
        $clientId = get_resource_id($socket);
        $len = @socket_recv($socket, $buffer, WS_BUFFER_SIZE, 0);

        if ($len === false) {
            echo "socket_recv error for client {$clientId}: " . socket_strerror(socket_last_error($socket)) . PHP_EOL;
            disconnectClient($clientId, 'recv_error');
            continue;
        }

        if ($len === 0) {
            disconnectClient($clientId, 'closed');
            continue;
        }

        $message = unmask($buffer);
        if ($message === '') {
            continue;
        }

        $payload = json_decode($message, true);
        if (!is_array($payload) || !isset($payload['type'])) {
            sendEnvelope($socket, ['type' => 'error', 'error' => 'Invalid message payload']);
            continue;
        }

        $type = $payload['type'];
        switch ($type) {
            case 'pong':
                $clientMeta[$clientId]['last_pong'] = $now;
                break;
            case 'join_room':
                handleJoinRoom($clientId, $payload);
                break;
            case 'leave_room':
                handleLeaveRoom($clientId, $payload);
                break;
            case 'chat_message':
                handleChatMessage($clientId, $payload);
                break;
            case 'direct_message':
                handleDirectMessage($clientId, $payload);
                break;
            case 'ping':
                sendEnvelope($socket, ['type' => 'pong']);
                break;
            default:
                sendEnvelope($socket, ['type' => 'error', 'error' => 'Unknown message type']);
        }
    }
}

socket_close($serverSocket);
exit;

// === Helpers ================================================================

function createServerSocket(string $host, int $port)
{
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
        throw new RuntimeException('socket_create failed: ' . socket_strerror(socket_last_error()));
    }

    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    if (!@socket_bind($socket, $host, $port)) {
        $err = socket_strerror(socket_last_error($socket));
        throw new RuntimeException("socket_bind failed on {$host}:{$port}: {$err}");
    }
    if (!@socket_listen($socket, WS_BACKLOG)) {
        $err = socket_strerror(socket_last_error($socket));
        throw new RuntimeException("socket_listen failed: {$err}");
    }

    return $socket;
}

function performHandshake($clientSocket): ?array
{
    $buffer = '';
    if (@socket_recv($clientSocket, $buffer, WS_BUFFER_SIZE, 0) <= 0) {
        return null;
    }

    $headers = [];
    foreach (explode("\r\n", $buffer) as $line) {
        if (strpos($line, ':') !== false) {
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $headers[strtolower($key)] = $value;
        }
    }

    if (!isset($headers['sec-websocket-key'])) {
        return null;
    }

    $sessionId = extractSessionId($headers['cookie'] ?? '');
    if ($sessionId === null) {
        sendHttpError($clientSocket, 401, 'Unauthorized');
        return null;
    }

    $session = loadSession($sessionId);
    if ($session === null) {
        sendHttpError($clientSocket, 401, 'Unauthorized');
        return null;
    }

    $secAccept = base64_encode(sha1($headers['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$secAccept}\r\n\r\n";
    socket_write($clientSocket, $response, strlen($response));

    return $session + ['session_id' => $sessionId];
}

function extractSessionId(string $cookieHeader): ?string
{
    foreach (explode(';', $cookieHeader) as $part) {
        $kv = array_map('trim', explode('=', $part, 2));
        if (count($kv) === 2 && $kv[0] === session_name()) {
            return $kv[1];
        }
    }
    return null;
}

function loadSession(string $sessionId): ?array
{
    if ($sessionId === '') {
        return null;
    }

    session_id($sessionId);
    if (!@session_start()) {
        return null;
    }

    $data = [
        'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'username' => isset($_SESSION['username']) ? (string) $_SESSION['username'] : null,
        'screen_name' => isset($_SESSION['screen_name']) ? (string) $_SESSION['screen_name'] : null,
    ];

    session_write_close();

    if ($data['user_id'] === null || $data['username'] === null || $data['screen_name'] === null) {
        return null;
    }

    return $data;
}

function sendHttpError($socket, int $code, string $message): void
{
    $response = "HTTP/1.1 {$code} {$message}\r\n"
        . "Content-Type: text/plain\r\n"
        . "Connection: close\r\n"
        . "Content-Length: " . strlen($message) . "\r\n\r\n"
        . $message;
    @socket_write($socket, $response, strlen($response));
}

function sendEnvelope($socket, array $payload): void
{
    $json = json_encode($payload);
    if ($json === false) {
        return;
    }
    $frame = frame($json);
    @socket_write($socket, $frame, strlen($frame));
}

function frame(string $payload): string
{
    $length = strlen($payload);
    $frame = [chr(129)];

    if ($length <= 125) {
        $frame[] = chr($length);
    } elseif ($length <= 65535) {
        $frame[] = chr(126);
        $frame[] = chr(($length >> 8) & 255);
        $frame[] = chr($length & 255);
    } else {
        $frame[] = chr(127);
        for ($i = 7; $i >= 0; $i--) {
            $frame[] = chr(($length >> ($i * 8)) & 255);
        }
    }

    return implode('', $frame) . $payload;
}

function unmask(string $payload): string
{
    $length = ord($payload[1]) & 127;
    $mask = '';
    $data = '';

    if ($length === 126) {
        $mask = substr($payload, 4, 4);
        $data = substr($payload, 8);
    } elseif ($length === 127) {
        $mask = substr($payload, 10, 4);
        $data = substr($payload, 14);
    } else {
        $mask = substr($payload, 2, 4);
        $data = substr($payload, 6);
    }

    $text = '';
    $dataLength = strlen($data);
    for ($i = 0; $i < $dataLength; $i++) {
        $text .= $data[$i] ^ $mask[$i % 4];
    }

    return $text;
}

function disconnectClient(int $clientId, string $reason = 'closed'): void
{
    global $clientSockets, $clientMeta, $rooms;

    if (!isset($clientSockets[$clientId])) {
        return;
    }

    $meta = $clientMeta[$clientId] ?? null;
    if ($meta) {
        foreach (array_keys($meta['rooms']) as $roomId) {
            handleLeaveRoom($clientId, ['roomId' => $roomId], false);
        }
        echo sprintf("Disconnected client %s (%s)\n", $meta['peer'], $reason);
    }

    @socket_close($clientSockets[$clientId]);
    unset($clientSockets[$clientId], $clientMeta[$clientId]);
}

function handleJoinRoom(int $clientId, array $payload): void
{
    global $clientMeta, $rooms;

    $roomId = (int) ($payload['roomId'] ?? 0);
    if ($roomId <= 0) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'join', 'error' => 'roomId is required']);
        return;
    }

    if (isset($clientMeta[$clientId]['rooms'][$roomId])) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'room_joined', 'roomId' => $roomId, 'messages' => [], 'dms' => []]);
        return;
    }

    $pdo = get_db();
    $roomStmt = $pdo->prepare('SELECT id, chatroomName FROM list_of_chatrooms WHERE id = :id');
    $roomStmt->execute([':id' => $roomId]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'join', 'error' => 'Chat room not found']);
        return;
    }

    // Insert presence if not already there
    $presenceCheck = $pdo->prepare('SELECT id FROM current_chatroom_occupants WHERE chatroom_id = :room AND user_id = :user LIMIT 1');
    $presenceCheck->execute([':room' => $roomId, ':user' => $clientMeta[$clientId]['user_id']]);
    $insertedPresence = false;
    if (!$presenceCheck->fetchColumn()) {
        $insertPresence = $pdo->prepare(
            'INSERT INTO current_chatroom_occupants (chatroom_id, user_id, socket_id) VALUES (:room, :user, 0)'
        );
        $insertPresence->execute([':room' => $roomId, ':user' => $clientMeta[$clientId]['user_id']]);

        $joinMsg = $pdo->prepare('INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)');
        $joinMsg->execute([
            ':room' => $roomId,
            ':user' => $clientMeta[$clientId]['user_id'],
            ':body' => sprintf('%s joined the chat', $clientMeta[$clientId]['screen_name']),
        ]);
        $joinMessagePayload = [
            'type' => 'message',
            'roomId' => $roomId,
            'message' => [
                'id' => (int) $pdo->lastInsertId(),
                'body' => sprintf('%s joined the chat', $clientMeta[$clientId]['screen_name']),
                'createdAt' => date('Y-m-d H:i:s'),
                'sender' => $clientMeta[$clientId]['screen_name'],
            ],
        ];
        $insertedPresence = true;
    }

    $clientMeta[$clientId]['rooms'][$roomId] = true;
    $rooms[$roomId][$clientId] = true;

    // Pull recent history
    $messages = loadRecentMessages($pdo, $roomId);
    $dms = loadRecentDMs($pdo, $roomId, $clientMeta[$clientId]['user_id']);

    sendEnvelope($clientMeta[$clientId]['socket'], [
        'type' => 'room_joined',
        'roomId' => $roomId,
        'roomName' => $room['chatroomName'],
        'messages' => $messages,
        'dms' => $dms,
    ]);

    if ($insertedPresence) {
        broadcastPresence($roomId, [
            'type' => 'presence',
            'roomId' => $roomId,
            'action' => 'join',
            'user' => [
                'id' => $clientMeta[$clientId]['user_id'],
                'screenName' => $clientMeta[$clientId]['screen_name'],
            ],
        ], $clientId);

        broadcastToRoom($roomId, $joinMessagePayload, $clientId);
    }
}

function handleLeaveRoom(int $clientId, array $payload, bool $explicit = true): void
{
    global $clientMeta, $rooms;

    $roomId = (int) ($payload['roomId'] ?? 0);
    if ($roomId <= 0 || !isset($clientMeta[$clientId]['rooms'][$roomId])) {
        return;
    }

    unset($clientMeta[$clientId]['rooms'][$roomId]);
    unset($rooms[$roomId][$clientId]);
    if (empty($rooms[$roomId])) {
        unset($rooms[$roomId]);
    }

    $pdo = get_db();
    $deletePresence = $pdo->prepare('DELETE FROM current_chatroom_occupants WHERE chatroom_id = :room AND user_id = :user');
    $deletePresence->execute([':room' => $roomId, ':user' => $clientMeta[$clientId]['user_id']]);
    $removed = $deletePresence->rowCount() > 0;

    if ($removed) {
        $leaveMsg = $pdo->prepare('INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)');
        $leaveMsg->execute([
            ':room' => $roomId,
            ':user' => $clientMeta[$clientId]['user_id'],
            ':body' => sprintf('%s left the chat', $clientMeta[$clientId]['screen_name']),
        ]);
        $leaveMessageId = (int) $pdo->lastInsertId();
        $leavePayload = [
            'type' => 'message',
            'roomId' => $roomId,
            'message' => [
                'id' => $leaveMessageId,
                'body' => sprintf('%s left the chat', $clientMeta[$clientId]['screen_name']),
                'createdAt' => date('Y-m-d H:i:s'),
                'sender' => $clientMeta[$clientId]['screen_name'],
            ],
        ];
    }

    if ($explicit) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'room_left', 'roomId' => $roomId]);
    }

    if ($removed) {
        broadcastPresence($roomId, [
            'type' => 'presence',
            'roomId' => $roomId,
            'action' => 'leave',
            'user' => [
                'id' => $clientMeta[$clientId]['user_id'],
                'screenName' => $clientMeta[$clientId]['screen_name'],
            ],
        ]);

        if (isset($leavePayload)) {
            broadcastToRoom($roomId, $leavePayload);
        }
    }
}

function handleChatMessage(int $clientId, array $payload): void
{
    global $clientMeta, $rooms;

    $roomId = (int) ($payload['roomId'] ?? 0);
    $body = isset($payload['body']) ? trim((string) $payload['body']) : '';

    if ($roomId <= 0 || $body === '') {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'chat', 'error' => 'Invalid chat payload']);
        return;
    }

    if (!isset($clientMeta[$clientId]['rooms'][$roomId])) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'chat', 'error' => 'Join the room first']);
        return;
    }

    $pdo = get_db();
    $roomStmt = $pdo->prepare('SELECT id FROM list_of_chatrooms WHERE id = :id');
    $roomStmt->execute([':id' => $roomId]);
    if (!$roomStmt->fetchColumn()) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'chat', 'error' => 'Chat room not found']);
        return;
    }

    $insert = $pdo->prepare('INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)');
    $insert->execute([
        ':room' => $roomId,
        ':user' => $clientMeta[$clientId]['user_id'],
        ':body' => $body,
    ]);
    $messageId = (int) $pdo->lastInsertId();
    $createdAt = date('Y-m-d H:i:s');

    $payload = [
        'type' => 'message',
        'roomId' => $roomId,
        'message' => [
            'id' => $messageId,
            'body' => $body,
            'createdAt' => $createdAt,
            'sender' => $clientMeta[$clientId]['screen_name'],
        ],
    ];

    broadcastToRoom($roomId, $payload);
}

function handleDirectMessage(int $clientId, array $payload): void
{
    global $clientMeta, $rooms;

    $roomId = (int) ($payload['roomId'] ?? 0);
    $body = isset($payload['body']) ? trim((string) $payload['body']) : '';
    $recipients = $payload['recipients'] ?? [];

    if ($roomId <= 0 || $body === '' || !is_array($recipients) || count($recipients) === 0) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'dm', 'error' => 'Invalid DM payload']);
        return;
    }

    if (!isset($clientMeta[$clientId]['rooms'][$roomId])) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'dm', 'error' => 'Join the room first']);
        return;
    }

    $pdo = get_db();
    $roomStmt = $pdo->prepare('SELECT id FROM list_of_chatrooms WHERE id = :id');
    $roomStmt->execute([':id' => $roomId]);
    if (!$roomStmt->fetchColumn()) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'dm', 'error' => 'Chat room not found']);
        return;
    }

    $normalized = [];
    foreach ($recipients as $name) {
        $trimmed = trim((string) $name);
        if ($trimmed !== '') {
            $normalized[$trimmed] = true;
        }
    }
    $names = array_keys($normalized);
    if (empty($names)) {
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'dm', 'error' => 'Recipients required']);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $pdo->prepare("SELECT id, screenName FROM users WHERE screenName IN ({$placeholders})");
    $stmt->execute($names);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resolved = [];
    foreach ($rows as $row) {
        $resolved[$row['screenName']] = (int) $row['id'];
    }

    $missing = array_values(array_diff($names, array_keys($resolved)));
    if (!empty($missing)) {
        sendEnvelope($clientMeta[$clientId]['socket'], [
            'type' => 'error',
            'context' => 'dm',
            'error' => 'Unknown recipients',
            'missing' => $missing,
        ]);
        return;
    }

    $pdo->beginTransaction();
    try {
        $insertDm = $pdo->prepare('INSERT INTO direct_messages (sender_id, body) VALUES (:sender, :body)');
        $insertDm->execute([
            ':sender' => $clientMeta[$clientId]['user_id'],
            ':body' => $body,
        ]);
        $dmId = (int) $pdo->lastInsertId();

        $recipientIds = array_values($resolved);
        $recipientIds[] = $clientMeta[$clientId]['user_id'];
        $recipientIds = array_unique($recipientIds);

        $insRec = $pdo->prepare(
            'INSERT IGNORE INTO direct_message_recipients (dm_id, recipient_id, chatroom_id) VALUES (:dm, :rid, :room)'
        );
        foreach ($recipientIds as $rid) {
            $insRec->execute([
                ':dm' => $dmId,
                ':rid' => $rid,
                ':room' => $roomId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        sendEnvelope($clientMeta[$clientId]['socket'], ['type' => 'error', 'context' => 'dm', 'error' => 'Failed to send DM']);
        return;
    }

    $message = [
        'id' => $dmId,
        'body' => $body,
        'createdAt' => date('Y-m-d H:i:s'),
        'sender' => $clientMeta[$clientId]['screen_name'],
        'isDM' => true,
    ];

    // Deliver to sender and target recipients (if connected)
    $messageEnvelope = [
        'type' => 'direct_message',
        'roomId' => $roomId,
        'message' => $message,
    ];

    deliverDirectMessage($roomId, $recipientIds, $messageEnvelope);
}

function loadRecentMessages(PDO $pdo, int $roomId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.id, m.body, m.created_at, u.screenName
         FROM messages m
         JOIN users u ON u.id = m.user_id
         WHERE m.chatroom_id = :room
         ORDER BY m.id DESC
         LIMIT 50'
    );
    $stmt->execute([':room' => $roomId]);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    return array_map(static fn ($row) => [
        'id' => (int) $row['id'],
        'body' => (string) $row['body'],
        'createdAt' => (string) $row['created_at'],
        'sender' => (string) $row['screenName'],
    ], $rows);
}

function loadRecentDMs(PDO $pdo, int $roomId, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT d.id, d.body, d.created_at, u.screenName AS sender
         FROM direct_messages d
         JOIN direct_message_recipients r ON r.dm_id = d.id
         JOIN users u ON u.id = d.sender_id
         WHERE r.chatroom_id = :room AND r.recipient_id = :me
         ORDER BY d.id DESC
         LIMIT 50'
    );
    $stmt->execute([':room' => $roomId, ':me' => $userId]);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    return array_map(static fn ($row) => [
        'id' => (int) $row['id'],
        'body' => (string) $row['body'],
        'createdAt' => (string) $row['created_at'],
        'sender' => (string) $row['sender'],
        'isDM' => true,
    ], $rows);
}

function initializeStorage(): void
{
    $pdo = get_db();

    $pdo->exec('CREATE TABLE IF NOT EXISTS direct_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS direct_message_recipients (
        dm_id INT NOT NULL,
        recipient_id INT NOT NULL,
        chatroom_id INT NOT NULL,
        PRIMARY KEY (dm_id, recipient_id),
        FOREIGN KEY (dm_id) REFERENCES direct_messages(id) ON DELETE CASCADE,
        FOREIGN KEY (recipient_id) REFERENCES users(id),
        FOREIGN KEY (chatroom_id) REFERENCES list_of_chatrooms(id)
    )');

    try {
        $columnCheck = $pdo->query("SHOW COLUMNS FROM direct_message_recipients LIKE 'chatroom_id'");
        if (!$columnCheck->fetch()) {
            $pdo->exec('ALTER TABLE direct_message_recipients ADD COLUMN chatroom_id INT NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        // ignore; schema might already be correct or database user lacks ALTER
    }
}

function broadcastToRoom(int $roomId, array $payload, ?int $excludeClientId = null): void
{
    global $rooms, $clientMeta;

    if (!isset($rooms[$roomId])) {
        return;
    }

    foreach ($rooms[$roomId] as $clientId => $_) {
        if ($excludeClientId !== null && $clientId === $excludeClientId) {
            continue;
        }
        if (!isset($clientMeta[$clientId])) {
            continue;
        }
        sendEnvelope($clientMeta[$clientId]['socket'], $payload);
    }
}

function broadcastPresence(int $roomId, array $payload, ?int $excludeClientId = null): void
{
    global $rooms, $clientMeta;

    if (!isset($rooms[$roomId])) {
        return;
    }

    foreach ($rooms[$roomId] as $clientId => $_) {
        if ($excludeClientId !== null && $clientId === $excludeClientId) {
            continue;
        }
        if (!isset($clientMeta[$clientId])) {
            continue;
        }
        sendEnvelope($clientMeta[$clientId]['socket'], $payload);
    }
}

function deliverDirectMessage(int $roomId, array $recipientIds, array $payload): void
{
    global $clientMeta;

    $recipientIds = array_map('intval', $recipientIds);

    foreach ($clientMeta as $clientId => $meta) {
        if (!isset($meta['rooms'][$roomId])) {
            continue;
        }
        if (!in_array($meta['user_id'], $recipientIds, true)) {
            continue;
        }
        sendEnvelope($meta['socket'], $payload);
    }
}
