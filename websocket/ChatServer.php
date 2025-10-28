<?php
declare(strict_types=1);

namespace BoxChat\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatServer implements MessageComponentInterface
{
    protected $clients;
    protected $roomConnections; // room_id => [connections]
    protected $connectionInfo;  // connection => ['user_id' => x, 'room_id' => y]

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->roomConnections = [];
        $this->connectionInfo = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $this->connectionInfo[$conn] = ['user_id' => null, 'room_id' => null, 'username' => null];
        
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        try {
            $data = json_decode($msg, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->sendError($from, 'Invalid JSON');
                return;
            }

            $action = $data['action'] ?? '';

            switch ($action) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;
                case 'join':
                    $this->handleJoin($from, $data);
                    break;
                case 'leave':
                    $this->handleLeave($from, $data);
                    break;
                case 'message':
                    $this->handleMessage($from, $data);
                    break;
                case 'ping':
                    $this->handlePing($from);
                    break;
                default:
                    $this->sendError($from, 'Unknown action');
            }
        } catch (\Exception $e) {
            error_log("WebSocket error: " . $e->getMessage());
            $this->sendError($from, 'Server error');
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        // Leave any rooms
        $info = $this->connectionInfo[$conn] ?? null;
        if ($info && $info['room_id']) {
            $this->removeFromRoom($conn, $info['room_id']);
        }

        $this->clients->detach($conn);
        $this->connectionInfo->detach($conn);
        
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        error_log("WebSocket error on connection {$conn->resourceId}: {$e->getMessage()}");
        $conn->close();
    }

    protected function handleAuth(ConnectionInterface $conn, array $data)
    {
        $userId = $data['user_id'] ?? null;
        $username = $data['username'] ?? null;

        if (!$userId || !$username) {
            $this->sendError($conn, 'Missing user_id or username');
            return;
        }

        $this->connectionInfo[$conn]['user_id'] = (int)$userId;
        $this->connectionInfo[$conn]['username'] = $username;

        $this->send($conn, [
            'type' => 'auth',
            'status' => 'success',
            'message' => 'Authenticated'
        ]);

        echo "User {$username} (ID: {$userId}) authenticated on connection {$conn->resourceId}\n";
    }

    protected function handleJoin(ConnectionInterface $conn, array $data)
    {
        $roomId = $data['room_id'] ?? null;
        $info = $this->connectionInfo[$conn] ?? null;

        if (!$info || !$info['user_id']) {
            $this->sendError($conn, 'Not authenticated');
            return;
        }

        if (!$roomId) {
            $this->sendError($conn, 'Missing room_id');
            return;
        }

        // Leave previous room if any
        if ($info['room_id']) {
            $this->removeFromRoom($conn, $info['room_id']);
        }

        // Join new room
        $roomId = (int)$roomId;
        if (!isset($this->roomConnections[$roomId])) {
            $this->roomConnections[$roomId] = new \SplObjectStorage();
        }

        $this->roomConnections[$roomId]->attach($conn);
        $this->connectionInfo[$conn]['room_id'] = $roomId;

        $this->send($conn, [
            'type' => 'join',
            'status' => 'success',
            'room_id' => $roomId
        ]);

        echo "User {$info['username']} joined room {$roomId}\n";
    }

    protected function handleLeave(ConnectionInterface $conn, array $data)
    {
        $info = $this->connectionInfo[$conn] ?? null;
        if (!$info || !$info['room_id']) {
            return;
        }

        $roomId = $info['room_id'];
        $this->removeFromRoom($conn, $roomId);

        $this->send($conn, [
            'type' => 'leave',
            'status' => 'success'
        ]);

        echo "User {$info['username']} left room {$roomId}\n";
    }

    protected function handleMessage(ConnectionInterface $from, array $data)
    {
        $info = $this->connectionInfo[$from] ?? null;

        if (!$info || !$info['user_id']) {
            $this->sendError($from, 'Not authenticated');
            return;
        }

        if (!$info['room_id']) {
            $this->sendError($from, 'Not in a room');
            return;
        }

        $messageId = $data['id'] ?? null;
        $body = $data['body'] ?? '';
        $sender = $data['sender'] ?? '';
        $createdAt = $data['createdAt'] ?? date('Y-m-d H:i:s');

        if (!$messageId || !$body) {
            $this->sendError($from, 'Missing message data');
            return;
        }

        // Broadcast to all users in the same room
        $roomId = $info['room_id'];
        $this->broadcastToRoom($roomId, [
            'type' => 'message',
            'id' => $messageId,
            'body' => $body,
            'sender' => $sender,
            'createdAt' => $createdAt,
            'room_id' => $roomId
        ]);

        echo "Message from {$info['username']} in room {$roomId}: {$body}\n";
    }

    protected function handlePing(ConnectionInterface $conn)
    {
        $this->send($conn, [
            'type' => 'pong',
            'timestamp' => time()
        ]);
    }

    protected function removeFromRoom(ConnectionInterface $conn, int $roomId)
    {
        if (isset($this->roomConnections[$roomId])) {
            $this->roomConnections[$roomId]->detach($conn);
            
            // Clean up empty rooms
            if ($this->roomConnections[$roomId]->count() === 0) {
                unset($this->roomConnections[$roomId]);
            }
        }

        $this->connectionInfo[$conn]['room_id'] = null;
    }

    protected function broadcastToRoom(int $roomId, array $message)
    {
        if (!isset($this->roomConnections[$roomId])) {
            return;
        }

        $json = json_encode($message);
        foreach ($this->roomConnections[$roomId] as $client) {
            $client->send($json);
        }
    }

    protected function send(ConnectionInterface $conn, array $data)
    {
        $conn->send(json_encode($data));
    }

    protected function sendError(ConnectionInterface $conn, string $error)
    {
        $this->send($conn, [
            'type' => 'error',
            'message' => $error
        ]);
    }
}
