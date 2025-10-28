<?php
$autoload = __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/db.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(\Dotenv\Dotenv::class)) {
        \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    }
}

// Resolve required credentials from environment; fail fast if missing
    $requireEnv = static function (string $key): string {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        // Treat empty-string as missing for critical credentials
        if ($value === null || (is_string($value) && trim($value) === '')) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Database configuration missing',
                'details' => sprintf('Required environment variable %s is not set', $key),
            ]);
            exit;
        }
        return (string) $value;
    };
// Load host/port strictly from environment
$envHost = $requireEnv('WS_HOST') ?: '0.0.0.0';
$envPort = $requireEnv('WS_PORT') ?: '8080';

// Validate
if (!filter_var($envHost, FILTER_VALIDATE_IP)) {
    fwrite(STDERR, "Invalid WS_HOST value: {$envHost}. Using 0.0.0.0\n");
    $envHost = '0.0.0.0';
}
if (!ctype_digit((string)$envPort) || (int)$envPort <= 0 || (int)$envPort > 65535) {
    fwrite(STDERR, "Invalid WS_PORT value: {$envPort}. Using 8080\n");
    $envPort = '8080';
}

$bindAddress = sprintf('%s:%d', $envHost, $envPort);

$server = @stream_socket_server(
    'tcp://' . $bindAddress,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);

if ($server === false) {
    fwrite(STDERR, sprintf("Unable to start websocket server on %s: %s\n", $bindAddress, $errstr));
    exit(1);
}

stream_set_blocking($server, false);
set_time_limit(0);

// Connect to your DB as before
$pdo = get_db();

/**
 * @var array<int, array{
 *     socket: resource,
 *     buffer: string,
 *     handshake: bool,
 *     token?: string,
 *     socketRecordId?: int,
 *     userId?: int,
 *     screenName?: string,
 *     currentRoom?: int|null
 * }>
 */
$clients = [];

/** @var array<int, array<int, bool>> userId => [clientId => true] */
$clientsByUser = [];

/** @var array<int, array<int, bool>> roomId => [clientId => true] */
$clientsByRoom = [];

$pendingClose = [];

// Log startup
logMessage(sprintf('Websocket server listening on %s', $bindAddress));

while (true) {
    $readSockets = [$server];
    foreach ($clients as $id => $client) {
        $readSockets[] = $client['socket'];
    }

    $writeSockets = null;
    $exceptSockets = null;

    $numChanged = @stream_select($readSockets, $writeSockets, $exceptSockets, 1, 0);
    if ($numChanged === false) {
        continue;
    }

    if (in_array($server, $readSockets, true)) {
        $newSocket = @stream_socket_accept($server, 0);
        if ($newSocket !== false) {
            stream_set_blocking($newSocket, false);
            $id = (int) $newSocket;
            $clients[$id] = [
                'socket' => $newSocket,
                'buffer' => '',
                'handshake' => false,
                'currentRoom' => null,
            ];
        }
        $readSockets = array_filter(
            $readSockets,
            static fn ($sock) => $sock !== $server
        );
    }

    foreach ($readSockets as $socket) {
        $clientId = (int) $socket;
        if (!isset($clients[$clientId])) {
            continue;
        }

        $chunk = @fread($socket, 32768);
        if ($chunk === '' || $chunk === false) {
            scheduleClose($clientId, 'read_closed');
            continue;
        }

        $clients[$clientId]['buffer'] .= $chunk;

        if (!$clients[$clientId]['handshake']) {
            if (strpos($clients[$clientId]['buffer'], "\r\n\r\n") !== false) {
                if (!performHandshake($clientId, $clients[$clientId]['buffer'])) {
                    scheduleClose($clientId, 'handshake_failed');
                } else {
                    $clients[$clientId]['buffer'] = '';
                }
            }
            continue;
        }

        while (true) {
            $frame = decodeFrame($clients[$clientId]['buffer']);
            if ($frame === null) {
                break;
            }

            if ($frame['opcode'] === 0x8) {
                scheduleClose($clientId, 'close_frame');
                break;
            }

            if ($frame['opcode'] === 0x9) {
                sendFrame($clientId, $frame['payload'], 0xA);
                continue;
            }

            if ($frame['opcode'] !== 0x1) {
                continue;
            }

            handlePayload($clientId, $frame['payload']);
        }
    }

    if (!empty($pendingClose)) {
        foreach ($pendingClose as $clientId => $_reason) {
            forceClose($clientId);
        }
        $pendingClose = [];
    }
}

// -----------------------------------------------------------------------------
// Helper functions
// -----------------------------------------------------------------------------

function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    fwrite(STDOUT, sprintf("[%s] %s\n", $timestamp, $message));
}

function authenticateToken(string $token): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT s.id, s.user_id, u.screenName
         FROM sockets s
         JOIN users u ON u.id = s.user_id
         WHERE s.socket_token = :token AND s.disconnected_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $pdo->prepare('UPDATE sockets SET connected_at = NOW() WHERE id = :id')->execute([':id' => $row['id']]);

    return [
        'socket_id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'screen_name' => (string) $row['screenName'],
    ];
}

function performHandshake(int $clientId, string $buffer): bool
{
    global $clients;

    $headerEnd = strpos($buffer, "\r\n\r\n");
    if ($headerEnd === false) {
        return false;
    }
    $headerRaw = substr($buffer, 0, $headerEnd);
    $lines = explode("\r\n", $headerRaw);
    $requestLine = array_shift($lines);

    if (!is_string($requestLine) || !preg_match('#^GET\s+(\S+)\s+HTTP/1\.1$#i', $requestLine, $matches)) {
        sendHttpError($clientId, 400, 'Bad Request');
        return false;
    }

    $path = $matches[1];
    $headers = [];
    foreach ($lines as $line) {
        $delimiter = strpos($line, ':');
        if ($delimiter === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $delimiter)));
        $value = trim(substr($line, $delimiter + 1));
        $headers[$name] = $value;
    }

    $key = $headers['sec-websocket-key'] ?? null;
    if (!$key) {
        sendHttpError($clientId, 400, 'Missing websocket key');
        return false;
    }

    $url = parse_url($path);
    $query = [];
    if (isset($url['query'])) {
        parse_str($url['query'], $query);
    }

    $token = isset($query['token']) ? trim((string) $query['token']) : '';
    if ($token === '') {
        sendHttpError($clientId, 401, 'Missing token');
        return false;
    }

    $auth = authenticateToken($token);
    if ($auth === null) {
        sendHttpError($clientId, 401, 'Invalid token');
        return false;
    }

    $acceptKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $response = "HTTP/1.1 101 Switching Protocols\r\n";
    $response .= "Upgrade: websocket\r\n";
    $response .= "Connection: Upgrade\r\n";
    $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";

    @fwrite($clients[$clientId]['socket'], $response);

    $clients[$clientId]['handshake'] = true;
    $clients[$clientId]['token'] = $token;
    $clients[$clientId]['socketRecordId'] = $auth['socket_id'];
    $clients[$clientId]['userId'] = $auth['user_id'];
    $clients[$clientId]['screenName'] = $auth['screen_name'];

    $clientsByUser =& getClientsByUserRef();
    if (!isset($clientsByUser[$auth['user_id']])) {
        $clientsByUser[$auth['user_id']] = [];
    }
    $clientsByUser[$auth['user_id']][$clientId] = true;

    sendJson($clientId, [
        'type' => 'ready',
        'userId' => $auth['user_id'],
    ]);

    logMessage(sprintf('Client %d authenticated as user %d', $clientId, $auth['user_id']));

    return true;
}

function sendHttpError(int $clientId, int $status, string $message): void
{
    global $clients;

    if (!isset($clients[$clientId])) {
        return;
    }

    $body = sprintf("%d %s", $status, $message);
    $response = sprintf(
        "HTTP/1.1 %d %s\r\nContent-Type: text/plain\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
        $status,
        $message,
        strlen($body),
        $body
    );
    @fwrite($clients[$clientId]['socket'], $response);
}

function decodeFrame(string &$buffer): ?array
{
    $length = strlen($buffer);
    if ($length < 2) {
        return null;
    }

    $b1 = ord($buffer[0]);
    $b2 = ord($buffer[1]);

    $isMasked = ($b2 & 0x80) !== 0;
    $payloadLength = $b2 & 0x7F;
    $offset = 2;

    if ($payloadLength === 126) {
        if ($length < 4) {
            return null;
        }
        $payloadLength = unpack('n', substr($buffer, $offset, 2))[1];
        $offset += 2;
    } elseif ($payloadLength === 127) {
        if ($length < 10) {
            return null;
        }
        $bytes = substr($buffer, $offset, 8);
        $offset += 8;
        $payloadLength = 0;
        for ($i = 0; $i < 8; $i++) {
            $payloadLength = ($payloadLength << 8) | ord($bytes[$i]);
        }
    }

    $mask = '';
    if ($isMasked) {
        if ($length < $offset + 4) {
            return null;
        }
        $mask = substr($buffer, $offset, 4);
        $offset += 4;
    }

    if ($length < $offset + $payloadLength) {
        return null;
    }

    $payload = substr($buffer, $offset, $payloadLength);
    $buffer = substr($buffer, $offset + $payloadLength);

    if ($isMasked) {
        $unmasked = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $unmasked;
    }

    return [
        'fin' => ($b1 & 0x80) !== 0,
        'opcode' => $b1 & 0x0F,
        'payload' => $payload,
    ];
}

function encodeFrame(string $payload, int $opcode = 0x1): string
{
    $length = strlen($payload);
    $frame = chr(0x80 | ($opcode & 0x0F));

    if ($length <= 125) {
        $frame .= chr($length);
    } elseif ($length <= 0xFFFF) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $high = ($length & 0xFFFFFFFF00000000) >> 32;
        $low = $length & 0xFFFFFFFF;
        $frame .= chr(127) . pack('NN', $high, $low);
    }

    return $frame . $payload;
}

function sendFrame(int $clientId, string $payload, int $opcode = 0x1): void
{
    global $clients;
    if (!isset($clients[$clientId])) {
        return;
    }
    $frame = encodeFrame($payload, $opcode);
    @fwrite($clients[$clientId]['socket'], $frame);
}

function sendJson(int $clientId, array $payload): void
{
    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        logMessage('Failed to encode JSON payload: ' . $e->getMessage());
        return;
    }

    sendFrame($clientId, $json, 0x1);
}

function broadcastRoom(int $roomId, array $payload): void
{
    global $clientsByRoom;
    if (empty($clientsByRoom[$roomId])) {
        return;
    }

    foreach (array_keys($clientsByRoom[$roomId]) as $clientId) {
        sendJson($clientId, $payload);
    }
}

function sendAck(int $clientId, string $action, array $data = [], ?int $requestId = null): void
{
    $payload = array_merge([
        'type' => 'ack',
        'action' => $action,
        'status' => 'ok',
    ], $data);

    if ($requestId !== null) {
        $payload['requestId'] = $requestId;
    }

    sendJson($clientId, $payload);
}

function sendError(int $clientId, string $action, string $message, ?int $requestId = null, array $extra = []): void
{
    $payload = array_merge([
        'type' => 'error',
        'action' => $action,
        'message' => $message,
    ], $extra);

    if ($requestId !== null) {
        $payload['requestId'] = $requestId;
    }

    sendJson($clientId, $payload);
}

function insertMessage(int $roomId, int $userId, string $body): ?array
{
    global $pdo;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)'
        );
        $stmt->execute([
            ':room' => $roomId,
            ':user' => $userId,
            ':body' => $body,
        ]);
        $messageId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        logMessage('Failed to insert message: ' . $e->getMessage());
        return null;
    }

    return fetchMessage($messageId);
}

function fetchMessage(int $messageId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT m.id, m.body, m.created_at, m.chatroom_id, u.screenName
         FROM messages m
         JOIN users u ON u.id = m.user_id
         WHERE m.id = :id'
    );
    $stmt->execute([':id' => $messageId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'roomId' => (int) $row['chatroom_id'],
        'body' => (string) $row['body'],
        'createdAt' => (string) $row['created_at'],
        'sender' => (string) $row['screenName'],
        'isDM' => false,
    ];
}

function leaveRoom(int $clientId, int $roomId, bool $broadcast = true): void
{
    global $clients, $clientsByRoom, $pdo;

    if (!isset($clients[$clientId])) {
        return;
    }

    $client = &$clients[$clientId];
    $socketId = $client['socketRecordId'] ?? null;

    $stmt = $pdo->prepare(
        'DELETE FROM current_chatroom_occupants WHERE chatroom_id = :room AND socket_id = :socket'
    );
    $stmt->execute([
        ':room' => $roomId,
        ':socket' => $socketId,
    ]);

    $removed = $stmt->rowCount() > 0;

    unset($clientsByRoom[$roomId][$clientId]);
    if (isset($clientsByRoom[$roomId]) && empty($clientsByRoom[$roomId])) {
        unset($clientsByRoom[$roomId]);
    }

    if (isset($client['currentRoom']) && $client['currentRoom'] === $roomId) {
        $client['currentRoom'] = null;
    }

    if ($removed && $broadcast && isset($client['userId'], $client['screenName'])) {
        $message = insertMessage($roomId, $client['userId'], sprintf('%s left the chat', $client['screenName']));
        if ($message !== null) {
            broadcastRoom($roomId, [
                'type' => 'chat_message',
                'roomId' => $roomId,
                'message' => $message,
            ]);
        }
    }
}

function handlePayload(int $clientId, string $jsonPayload): void
{
    global $clients;

    if (!isset($clients[$clientId]) || !$clients[$clientId]['handshake']) {
        return;
    }

    $data = json_decode($jsonPayload, true);
    if (!is_array($data) || !isset($data['type'])) {
        sendError($clientId, 'unknown', 'Invalid payload');
        return;
    }

    $type = (string) $data['type'];
    $requestId = isset($data['requestId']) ? (int) $data['requestId'] : null;

    switch ($type) {
        case 'join':
            handleJoinCommand($clientId, $data, $requestId);
            break;
        case 'leave':
            handleLeaveCommand($clientId, $data, $requestId);
            break;
        case 'message':
            handleMessageCommand($clientId, $data, $requestId);
            break;
        case 'dm':
            handleDmCommand($clientId, $data, $requestId);
            break;
        case 'ping':
            sendAck($clientId, 'ping', [], $requestId);
            break;
        default:
            sendError($clientId, $type, 'Unsupported command', $requestId);
            break;
    }
}

function handleJoinCommand(int $clientId, array $payload, ?int $requestId): void
{
    global $clients, $clientsByRoom, $pdo;

    if (!isset($clients[$clientId]['userId'], $clients[$clientId]['screenName'])) {
        sendError($clientId, 'join', 'Not authenticated', $requestId);
        return;
    }

    $roomId = isset($payload['roomId']) ? (int) $payload['roomId'] : 0;
    $passphrase = isset($payload['passphrase']) ? (string) $payload['passphrase'] : null;

    if ($roomId <= 0) {
        sendError($clientId, 'join', 'Invalid room id', $requestId);
        return;
    }

    $stmt = $pdo->prepare('SELECT id, chatroomName, key_hash FROM list_of_chatrooms WHERE id = :id');
    $stmt->execute([':id' => $roomId]);
    $room = $stmt->fetch();

    if (!$room) {
        sendError($clientId, 'join', 'Room not found', $requestId);
        return;
    }

    if ($room['key_hash'] !== null && !password_verify((string) $passphrase, (string) $room['key_hash'])) {
        sendError($clientId, 'join', 'Incorrect password', $requestId);
        return;
    }

    if (!empty($clients[$clientId]['currentRoom']) && $clients[$clientId]['currentRoom'] !== $roomId) {
        leaveRoom($clientId, (int) $clients[$clientId]['currentRoom'], true);
    }

    $pdo->prepare('DELETE FROM current_chatroom_occupants WHERE socket_id = :socket')->execute([
        ':socket' => $clients[$clientId]['socketRecordId'],
    ]);

    $pdo->prepare('INSERT INTO current_chatroom_occupants (chatroom_id, user_id, socket_id) VALUES (:room, :user, :socket)')
        ->execute([
            ':room' => $roomId,
            ':user' => $clients[$clientId]['userId'],
            ':socket' => $clients[$clientId]['socketRecordId'],
        ]);

    $clients[$clientId]['currentRoom'] = $roomId;
    if (!isset($clientsByRoom[$roomId])) {
        $clientsByRoom[$roomId] = [];
    }
    $clientsByRoom[$roomId][$clientId] = true;

    $message = insertMessage($roomId, $clients[$clientId]['userId'], sprintf('%s joined the chat', $clients[$clientId]['screenName']));
    if ($message !== null) {
        broadcastRoom($roomId, [
            'type' => 'chat_message',
            'roomId' => $roomId,
            'message' => $message,
        ]);
    }

    sendAck($clientId, 'join', [
        'roomId' => $roomId,
        'roomName' => (string) $room['chatroomName'],
    ], $requestId);
}

function handleLeaveCommand(int $clientId, array $payload, ?int $requestId): void
{
    $roomId = isset($payload['roomId']) ? (int) $payload['roomId'] : 0;
    if ($roomId <= 0) {
        sendError($clientId, 'leave', 'Invalid room id', $requestId);
        return;
    }

    leaveRoom($clientId, $roomId, true);
    sendAck($clientId, 'leave', ['roomId' => $roomId], $requestId);
}

function handleMessageCommand(int $clientId, array $payload, ?int $requestId): void
{
    global $clients;

    if (!isset($clients[$clientId]['currentRoom'], $clients[$clientId]['userId'], $clients[$clientId]['screenName'])) {
        sendError($clientId, 'message', 'Join a room first', $requestId);
        return;
    }

    $roomId = isset($payload['roomId']) ? (int) $payload['roomId'] : 0;
    $body = isset($payload['body']) ? trim((string) $payload['body']) : '';

    if ($roomId <= 0 || $roomId !== $clients[$clientId]['currentRoom']) {
        sendError($clientId, 'message', 'Invalid room context', $requestId);
        return;
    }

    if ($body === '') {
        sendError($clientId, 'message', 'Message cannot be empty', $requestId);
        return;
    }

    $message = insertMessage($roomId, $clients[$clientId]['userId'], $body);
    if ($message === null) {
        sendError($clientId, 'message', 'Failed to persist message', $requestId);
        return;
    }

    broadcastRoom($roomId, [
        'type' => 'chat_message',
        'roomId' => $roomId,
        'message' => $message,
    ]);

    sendAck($clientId, 'message', [
        'roomId' => $roomId,
        'messageId' => $message['id'],
    ], $requestId);
}

function handleDmCommand(int $clientId, array $payload, ?int $requestId): void
{
    global $clients, $clientsByUser, $pdo;

    if (!isset($clients[$clientId]['currentRoom'], $clients[$clientId]['userId'], $clients[$clientId]['screenName'])) {
        sendError($clientId, 'dm', 'Join a room first', $requestId);
        return;
    }

    $roomId = isset($payload['roomId']) ? (int) $payload['roomId'] : 0;
    $body = isset($payload['body']) ? trim((string) $payload['body']) : '';
    $recipientsRaw = $payload['recipients'] ?? [];

    if ($roomId <= 0 || $roomId !== $clients[$clientId]['currentRoom']) {
        sendError($clientId, 'dm', 'Invalid room context', $requestId);
        return;
    }

    if ($body === '') {
        sendError($clientId, 'dm', 'Message cannot be empty', $requestId);
        return;
    }

    if (!is_array($recipientsRaw) || count($recipientsRaw) === 0) {
        sendError($clientId, 'dm', 'At least one recipient required', $requestId);
        return;
    }

    $normalized = [];
    foreach ($recipientsRaw as $value) {
        $name = trim((string) $value);
        if ($name !== '') {
            $normalized[$name] = true;
        }
    }

    if (empty($normalized)) {
        sendError($clientId, 'dm', 'Recipients not provided', $requestId);
        return;
    }

    $names = array_keys($normalized);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $pdo->prepare("SELECT id, screenName FROM users WHERE screenName IN ($placeholders)");
    $stmt->execute($names);
    $rows = $stmt->fetchAll();

    $targets = [];
    foreach ($rows as $row) {
        $targets[(string) $row['screenName']] = (int) $row['id'];
    }

    $missing = array_values(array_diff($names, array_keys($targets)));
    if (!empty($missing)) {
        sendError($clientId, 'dm', 'Unknown recipients', $requestId, ['missing' => $missing]);
        return;
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO direct_messages (sender_id, body) VALUES (:sender, :body)');
        $ins->execute([
            ':sender' => $clients[$clientId]['userId'],
            ':body' => $body,
        ]);
        $dmId = (int) $pdo->lastInsertId();

        $recIns = $pdo->prepare(
            'INSERT INTO direct_message_recipients (dm_id, recipient_id, chatroom_id) VALUES (:dm, :rid, :room)'
        );

        $recipientIds = array_values($targets);
        $recipientIds[] = $clients[$clientId]['userId'];
        $recipientIds = array_values(array_unique($recipientIds));

        foreach ($recipientIds as $rid) {
            $recIns->execute([
                ':dm' => $dmId,
                ':rid' => $rid,
                ':room' => $roomId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        logMessage('Failed to insert DM: ' . $e->getMessage());
        sendError($clientId, 'dm', 'Unable to send DM', $requestId);
        return;
    }

    $stmt = $pdo->prepare('SELECT created_at FROM direct_messages WHERE id = :id');
    $stmt->execute([':id' => $dmId]);
    $createdAt = $stmt->fetchColumn();

    $payloadMessage = [
        'id' => $dmId,
        'roomId' => $roomId,
        'body' => $body,
        'createdAt' => $createdAt ?: date('Y-m-d H:i:s'),
        'sender' => $clients[$clientId]['screenName'],
        'isDM' => true,
    ];

    foreach ($recipientIds as $uid) {
        if (empty($clientsByUser[$uid])) {
            continue;
        }
        foreach (array_keys($clientsByUser[$uid]) as $targetClientId) {
            sendJson($targetClientId, [
                'type' => 'dm',
                'roomId' => $roomId,
                'message' => $payloadMessage,
            ]);
        }
    }

    sendAck($clientId, 'dm', [
        'roomId' => $roomId,
        'dmId' => $dmId,
    ], $requestId);
}

function scheduleClose(int $clientId, string $reason): void
{
    global $pendingClose;
    $pendingClose[$clientId] = $reason;
}

function forceClose(int $clientId): void
{
    global $clients, $clientsByUser, $clientsByRoom, $pdo;

    if (!isset($clients[$clientId])) {
        return;
    }

    $client = $clients[$clientId];
    $roomId = $client['currentRoom'] ?? null;

    if ($roomId !== null) {
        leaveRoom($clientId, (int) $roomId, true);
    } elseif (isset($client['socketRecordId'])) {
        $pdo->prepare('DELETE FROM current_chatroom_occupants WHERE socket_id = :socket')->execute([
            ':socket' => $client['socketRecordId'],
        ]);
    }

    if (isset($client['socketRecordId'])) {
        $pdo->prepare('UPDATE sockets SET disconnected_at = NOW() WHERE id = :id')->execute([
            ':id' => $client['socketRecordId'],
        ]);
    }

    if (isset($client['userId'])) {
        unset($clientsByUser[$client['userId']][$clientId]);
        if (empty($clientsByUser[$client['userId']])) {
            unset($clientsByUser[$client['userId']]);
        }
    }

    foreach ($clientsByRoom as $roomId => &$members) {
        if (isset($members[$clientId])) {
            unset($members[$clientId]);
            if (empty($members)) {
                unset($clientsByRoom[$roomId]);
            }
        }
    }
    unset($members);

    @fclose($client['socket']);
    unset($clients[$clientId]);
}

function &getClientsByUserRef(): array
{
    global $clientsByUser;
    return $clientsByUser;
}
