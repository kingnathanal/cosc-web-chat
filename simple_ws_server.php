<?php

declare(strict_types=1);

// Reuse project's DB helper and optional autoload
require_once __DIR__ . '/includes/db.php';

$requireEnv = static function (string $key, $default = null) {
    $v = getenv($key);
    if ($v === false) $v = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    return $v;
};

$HOST = (string)($requireEnv('WS_HOST', '0.0.0.0'));
$PORT = (int)($requireEnv('WS_PORT', '8080'));

$pdo = get_db();

// -- WebSocket framing primitives (text frames only) ---------------------
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

function decodeFrame(string &$buffer): ?array
{
    $length = strlen($buffer);
    if ($length < 2) return null;
    $b1 = ord($buffer[0]);
    $b2 = ord($buffer[1]);
    $isMasked = ($b2 & 0x80) !== 0;
    $payloadLength = $b2 & 0x7F;
    $offset = 2;

    if ($payloadLength === 126) {
        if ($length < 4) return null;
        $payloadLength = unpack('n', substr($buffer, $offset, 2))[1];
        $offset += 2;
    } elseif ($payloadLength === 127) {
        if ($length < 10) return null;
        $bytes = substr($buffer, $offset, 8);
        $offset += 8;
        $payloadLength = 0;
        for ($i = 0; $i < 8; $i++) {
            $payloadLength = ($payloadLength << 8) | ord($bytes[$i]);
        }
    }

    $mask = '';
    if ($isMasked) {
        if ($length < $offset + 4) return null;
        $mask = substr($buffer, $offset, 4);
        $offset += 4;
    }

    if ($length < $offset + $payloadLength) return null;
    $payload = substr($buffer, $offset, $payloadLength);
    $buffer = substr($buffer, $offset + $payloadLength);

    if ($isMasked && $mask !== '') {
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

// -- Helpers that interact with clients and DB ---------------------------
$clients = []; // clientId => [sock, buffer, handshake, socketRecordId, userId, screenName, currentRoom]
$clientsByUser = []; // userId => [clientId => true]
$clientsByRoom = []; // roomId => [clientId => true]
$pendingClose = [];

function logMessage(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    fwrite(STDOUT, "[{$ts}] {$msg}\n");
}

function sendFrame(int $clientId, string $payload, int $opcode = 0x1): void
{
    global $clients;
    if (!isset($clients[$clientId])) return;
    $frame = encodeFrame($payload, $opcode);
    @fwrite($clients[$clientId]['sock'], $frame);
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
    if (empty($clientsByRoom[$roomId])) return;
    foreach (array_keys($clientsByRoom[$roomId]) as $cid) {
        sendJson($cid, $payload);
    }
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
    if (!$row) return null;
    $pdo->prepare('UPDATE sockets SET connected_at = NOW() WHERE id = :id')->execute([':id' => $row['id']]);
    return [
        'socket_id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
        'screen_name' => (string)$row['screenName'],
    ];
}

function performHandshake(int $clientId, string $buffer): bool
{
    global $clients;
    global $pdo, $clientsByUser;

    $headerEnd = strpos($buffer, "\r\n\r\n");
    if ($headerEnd === false) return false;
    $headerRaw = substr($buffer, 0, $headerEnd);
    $lines = explode("\r\n", $headerRaw);
    $requestLine = array_shift($lines);

    if (!is_string($requestLine) || !preg_match('#^GET\s+(\S+)\s+HTTP/1\.1$#i', $requestLine, $matches)) {
        // Not a websocket upgrade request
        return false;
    }

    $headers = [];
    foreach ($lines as $line) {
        $delim = strpos($line, ':');
        if ($delim === false) continue;
        $name = strtolower(trim(substr($line, 0, $delim)));
        $value = trim(substr($line, $delim + 1));
        $headers[$name] = $value;
    }

    $key = $headers['sec-websocket-key'] ?? null;
    if (!$key) return false;

    $url = parse_url($matches[1]);
    $query = [];
    if (isset($url['query'])) parse_str($url['query'], $query);
    $token = isset($query['token']) ? trim((string)$query['token']) : '';
    if ($token === '') return false;

    $auth = authenticateToken($token);
    if ($auth === null) return false;

    $acceptKey = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    $response = "HTTP/1.1 101 Switching Protocols\r\n";
    $response .= "Upgrade: websocket\r\n";
    $response .= "Connection: Upgrade\r\n";
    $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";

    @fwrite($clients[$clientId]['sock'], $response);

    $clients[$clientId]['handshake'] = true;
    $clients[$clientId]['socketRecordId'] = $auth['socket_id'];
    $clients[$clientId]['userId'] = $auth['user_id'];
    $clients[$clientId]['screenName'] = $auth['screen_name'];

    if (!isset($clientsByUser[$auth['user_id']])) $clientsByUser[$auth['user_id']] = [];
    $clientsByUser[$auth['user_id']][$clientId] = true;

    sendJson($clientId, ['type' => 'ready', 'userId' => $auth['user_id']]);
    logMessage(sprintf('Client %d authenticated as user %d', $clientId, $auth['user_id']));
    return true;
}

function insertMessage(int $roomId, int $userId, string $body): ?array
{
    global $pdo;
    try {
        $stmt = $pdo->prepare('INSERT INTO messages (chatroom_id, user_id, body) VALUES (:room, :user, :body)');
        $stmt->execute([':room' => $roomId, ':user' => $userId, ':body' => $body]);
        $messageId = (int)$pdo->lastInsertId();
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
    if (!$row) return null;
    return [
        'id' => (int)$row['id'],
        'roomId' => (int)$row['chatroom_id'],
        'body' => (string)$row['body'],
        'createdAt' => (string)$row['created_at'],
        'sender' => (string)$row['screenName'],
        'isDM' => false,
    ];
}

function leaveRoom(int $clientId, int $roomId, bool $broadcast = true): void
{
    global $clients, $clientsByRoom, $pdo;
    if (!isset($clients[$clientId])) return;
    $client = &$clients[$clientId];
    $socketId = $client['socketRecordId'] ?? null;
    $stmt = $pdo->prepare('DELETE FROM current_chatroom_occupants WHERE chatroom_id = :room AND socket_id = :socket');
    $stmt->execute([':room' => $roomId, ':socket' => $socketId]);
    $removed = $stmt->rowCount() > 0;
    unset($clientsByRoom[$roomId][$clientId]);
    if (isset($clients[$clientId]['currentRoom']) && $clients[$clientId]['currentRoom'] === $roomId) {
        $clients[$clientId]['currentRoom'] = null;
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
    global $clients, $clientsByUser, $clientsByRoom, $pdo;
    if (!isset($clients[$clientId]) || !$clients[$clientId]['handshake']) return;
    $data = json_decode($jsonPayload, true);
    if (!is_array($data) || !isset($data['type'])) {
        sendJson($clientId, ['type' => 'error', 'action' => 'unknown', 'message' => 'Invalid payload']);
        return;
    }
    $type = (string)$data['type'];
    $requestId = isset($data['requestId']) ? (int)$data['requestId'] : null;

    switch ($type) {
        case 'join':
            // handle join
            $roomId = isset($data['roomId']) ? (int)$data['roomId'] : 0;
            $passphrase = isset($data['passphrase']) ? (string)$data['passphrase'] : null;
            if ($roomId <= 0) { sendJson($clientId, ['type' => 'error','action'=>'join','message'=>'Invalid room id','requestId'=>$requestId]); return; }
            $stmt = $pdo->prepare('SELECT id, chatroomName, key_hash FROM list_of_chatrooms WHERE id = :id');
            $stmt->execute([':id' => $roomId]);
            $room = $stmt->fetch();
            if (!$room) { sendJson($clientId, ['type'=>'error','action'=>'join','message'=>'Room not found','requestId'=>$requestId]); return; }
            if ($room['key_hash'] !== null && !password_verify((string)$passphrase, (string)$room['key_hash'])) {
                sendJson($clientId, ['type'=>'error','action'=>'join','message'=>'Incorrect password','requestId'=>$requestId]); return;
            }
            if (!empty($clients[$clientId]['currentRoom']) && $clients[$clientId]['currentRoom'] !== $roomId) {
                leaveRoom($clientId, (int)$clients[$clientId]['currentRoom'], true);
            }
            $pdo->prepare('DELETE FROM current_chatroom_occupants WHERE socket_id = :socket')->execute([':socket' => $clients[$clientId]['socketRecordId']]);
            $pdo->prepare('INSERT INTO current_chatroom_occupants (chatroom_id, user_id, socket_id) VALUES (:room, :user, :socket)')
                ->execute([':room' => $roomId, ':user' => $clients[$clientId]['userId'], ':socket' => $clients[$clientId]['socketRecordId']]);
            $clients[$clientId]['currentRoom'] = $roomId;
            if (!isset($clientsByRoom[$roomId])) $clientsByRoom[$roomId] = [];
            $clientsByRoom[$roomId][$clientId] = true;
            $message = insertMessage($roomId, $clients[$clientId]['userId'], sprintf('%s joined the chat', $clients[$clientId]['screenName']));
            if ($message !== null) {
                broadcastRoom($roomId, ['type'=>'chat_message','roomId'=>$roomId,'message'=>$message]);
            }
            sendJson($clientId, ['type'=>'ack','action'=>'join','status'=>'ok','roomId'=>$roomId,'roomName'=>$room['chatroomName'],'requestId'=>$requestId]);
            break;
        case 'leave':
            $roomId = isset($data['roomId']) ? (int)$data['roomId'] : 0;
            if ($roomId <= 0) { sendJson($clientId, ['type'=>'error','action'=>'leave','message'=>'Invalid room id','requestId'=>$requestId]); return; }
            leaveRoom($clientId, $roomId, true);
            sendJson($clientId, ['type'=>'ack','action'=>'leave','status'=>'ok','roomId'=>$roomId,'requestId'=>$requestId]);
            break;
        case 'message':
            if (!isset($clients[$clientId]['currentRoom'], $clients[$clientId]['userId'], $clients[$clientId]['screenName'])) {
                sendJson($clientId, ['type'=>'error','action'=>'message','message'=>'Join a room first','requestId'=>$requestId]); return;
            }
            $roomId = isset($data['roomId']) ? (int)$data['roomId'] : 0;
            $body = isset($data['body']) ? trim((string)$data['body']) : '';
            if ($roomId <= 0 || $roomId !== $clients[$clientId]['currentRoom']) { sendJson($clientId, ['type'=>'error','action'=>'message','message'=>'Invalid room context','requestId'=>$requestId]); return; }
            if ($body === '') { sendJson($clientId, ['type'=>'error','action'=>'message','message'=>'Message cannot be empty','requestId'=>$requestId]); return; }
            $message = insertMessage($roomId, $clients[$clientId]['userId'], $body);
            if ($message === null) { sendJson($clientId, ['type'=>'error','action'=>'message','message'=>'Failed to persist message','requestId'=>$requestId]); return; }
            broadcastRoom($roomId, ['type'=>'chat_message','roomId'=>$roomId,'message'=>$message]);
            sendJson($clientId, ['type'=>'ack','action'=>'message','status'=>'ok','roomId'=>$roomId,'messageId'=>$message['id'],'requestId'=>$requestId]);
            break;
        case 'dm':
            // simplified DM handling: mirror ws_server.php behavior
            if (!isset($clients[$clientId]['currentRoom'], $clients[$clientId]['userId'], $clients[$clientId]['screenName'])) { sendJson($clientId,['type'=>'error','action'=>'dm','message'=>'Join a room first','requestId'=>$requestId]); return; }
            $roomId = isset($data['roomId']) ? (int)$data['roomId'] : 0;
            $body = isset($data['body']) ? trim((string)$data['body']) : '';
            $recipientsRaw = $data['recipients'] ?? [];
            if ($roomId <= 0 || $roomId !== $clients[$clientId]['currentRoom']) { sendJson($clientId,['type'=>'error','action'=>'dm','message'=>'Invalid room context','requestId'=>$requestId]); return; }
            if ($body === '') { sendJson($clientId,['type'=>'error','action'=>'dm','message'=>'Message cannot be empty','requestId'=>$requestId]); return; }
            if (!is_array($recipientsRaw) || count($recipientsRaw) === 0) { sendJson($clientId,['type'=>'error','action'=>'dm','message'=>'At least one recipient required','requestId'=>$requestId]); return; }
            $normalized = [];
            foreach ($recipientsRaw as $v) { $n = trim((string)$v); if ($n !== '') $normalized[$n] = true; }
            if (empty($normalized)) { sendJson($clientId,['type'=>'error','action'=>'dm','message'=>'Recipients not provided','requestId'=>$requestId]); return; }
            $names = array_keys($normalized);
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $stmt = $pdo->prepare("SELECT id, screenName FROM users WHERE screenName IN ($placeholders)");
            $stmt->execute($names);
            $rows = $stmt->fetchAll();
            $targets = [];
            foreach ($rows as $r) $targets[(string)$r['screenName']] = (int)$r['id'];
            $missing = array_values(array_diff($names, array_keys($targets)));
            if (!empty($missing)) { sendJson($clientId,['type'=>'error','action'=>'dm','message'=>'Unknown recipients','requestId'=>$requestId,'missing'=>$missing]); return; }
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare('INSERT INTO direct_messages (sender_id, body) VALUES (:sender, :body)');
                $ins->execute([':sender' => $clients[$clientId]['userId'], ':body' => $body]);
                $dmId = (int)$pdo->lastInsertId();
                $recIns = $pdo->prepare('INSERT INTO direct_message_recipients (dm_id, recipient_id, chatroom_id) VALUES (:dm, :rid, :room)');
                $recipientIds = array_values($targets);
                $recipientIds[] = $clients[$clientId]['userId'];
                $recipientIds = array_values(array_unique($recipientIds));
                foreach ($recipientIds as $rid) {
                    $recIns->execute([':dm' => $dmId, ':rid' => $rid, ':room' => $roomId]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                logMessage('Failed to insert DM: ' . $e->getMessage());
                sendJson($clientId,['type'=>'error','action'=>'dm','message'=>'Unable to send DM','requestId'=>$requestId]);
                return;
            }
            $stmt = $pdo->prepare('SELECT created_at FROM direct_messages WHERE id = :id');
            $stmt->execute([':id' => $dmId]);
            $createdAt = $stmt->fetchColumn();
            $payloadMessage = ['id' => $dmId, 'roomId' => $roomId, 'body' => $body, 'createdAt' => $createdAt ?: date('Y-m-d H:i:s'), 'sender' => $clients[$clientId]['screenName'], 'isDM' => true];
            foreach ($recipientIds as $uid) {
                if (empty($clientsByUser[$uid])) continue;
                foreach (array_keys($clientsByUser[$uid]) as $targetClientId) {
                    sendJson($targetClientId, ['type' => 'dm', 'roomId' => $roomId, 'message' => $payloadMessage]);
                }
            }
            sendJson($clientId,['type'=>'ack','action'=>'dm','status'=>'ok','roomId'=>$roomId,'dmId'=>$dmId,'requestId'=>$requestId]);
            break;
        case 'ping':
            sendJson($clientId, ['type' => 'ack', 'action' => 'ping', 'status' => 'ok', 'requestId' => $requestId]);
            break;
        default:
            sendJson($clientId, ['type'=>'error','action'=>$type,'message'=>'Unsupported command','requestId'=>$requestId]);
            break;
    }
}

// -- Server loop --------------------------------------------------------
$server = @stream_socket_server("tcp://{$HOST}:{$PORT}", $errno, $errstr);
if (!$server) { fwrite(STDERR, "Unable to start server: $errstr\n"); exit(1); }
stream_set_blocking($server, false);
logMessage("WebSocket server listening on {$HOST}:{$PORT}");

while (true) {
    $read = [$server];
    foreach ($clients as $id => $c) $read[] = $c['sock'];
    $write = $except = [];

    $num = @stream_select($read, $write, $except, 1, 0);
    if ($num === false) continue;

    if (in_array($server, $read, true)) {
        $new = @stream_socket_accept($server, 0);
        if ($new !== false) {
            stream_set_blocking($new, false);
            $id = (int)$new;
            $clients[$id] = ['sock' => $new, 'buffer' => '', 'handshake' => false, 'currentRoom' => null];
        }
        $read = array_filter($read, static fn($s) => $s !== $server);
    }

    foreach ($read as $sock) {
        $clientId = (int)$sock;
        if (!isset($clients[$clientId])) continue;
        $chunk = @fread($sock, 32768);
        if ($chunk === '' || $chunk === false) { $pendingClose[$clientId] = 'read_closed'; continue; }
        $clients[$clientId]['buffer'] .= $chunk;

        if (!$clients[$clientId]['handshake']) {
            if (strpos($clients[$clientId]['buffer'], "\r\n\r\n") !== false) {
                if (!performHandshake($clientId, $clients[$clientId]['buffer'])) {
                    $pendingClose[$clientId] = 'handshake_failed';
                } else {
                    $clients[$clientId]['buffer'] = '';
                }
            }
            continue;
        }

        while (true) {
            $frame = decodeFrame($clients[$clientId]['buffer']);
            if ($frame === null) break;
            if ($frame['opcode'] === 0x8) { $pendingClose[$clientId] = 'close_frame'; break; }
            if ($frame['opcode'] === 0x9) { sendFrame($clientId, $frame['payload'], 0xA); continue; }
            if ($frame['opcode'] !== 0x1) continue;
            handlePayload($clientId, $frame['payload']);
        }
    }

    if (!empty($pendingClose)) {
        foreach ($pendingClose as $cid => $_) {
            if (!isset($clients[$cid])) continue;
            // graceful close: mark disconnected and cleanup
            $client = $clients[$cid];
            $socketRecord = $client['socketRecordId'] ?? null;
            if ($socketRecord !== null) {
                $pdo->prepare('UPDATE sockets SET disconnected_at = NOW() WHERE id = :id')->execute([':id' => $socketRecord]);
                $pdo->prepare('DELETE FROM current_chatroom_occupants WHERE socket_id = :socket')->execute([':socket' => $socketRecord]);
            }
            // remove from clientsByUser
            if (isset($client['userId'])) {
                global $clientsByUser;
                unset($clientsByUser[$client['userId']][$cid]);
            }
            // remove from clientsByRoom
            foreach ($clientsByRoom as $rid => &$members) {
                if (isset($members[$cid])) unset($members[$cid]);
            }
            @fclose($client['sock']);
            unset($clients[$cid]);
        }
        $pendingClose = [];
    }
}
