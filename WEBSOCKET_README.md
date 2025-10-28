# WebSocket Server Setup

This application now uses WebSocket for real-time chat messaging instead of HTTP polling.

## Prerequisites

- PHP 8.0 or higher
- Composer
- Port 8080 available for WebSocket server

## Installation

1. Install dependencies:
```bash
composer install
```

2. Start the WebSocket server:
```bash
php websocket/server.php
```

The server will start on `ws://localhost:8080`.

## Running the WebSocket Server

### Start Server
```bash
php websocket/server.php
```

### Run as Background Service (Linux/Mac)
```bash
nohup php websocket/server.php > websocket.log 2>&1 &
```

### Run with systemd (Production)

Create a systemd service file at `/etc/systemd/system/boxchat-websocket.service`:

```ini
[Unit]
Description=Box Chat WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/cosc-web-chat
ExecStart=/usr/bin/php /path/to/cosc-web-chat/websocket/server.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Then enable and start the service:
```bash
sudo systemctl enable boxchat-websocket
sudo systemctl start boxchat-websocket
sudo systemctl status boxchat-websocket
```

## How It Works

1. **WebSocket Server** (`websocket/server.php`): Runs on port 8080 and handles real-time connections
2. **Chat Server** (`websocket/ChatServer.php`): Manages WebSocket connections, authentication, rooms, and message broadcasting
3. **Frontend** (`common.js`): Connects to WebSocket server and handles real-time message delivery

## WebSocket Protocol

The WebSocket server accepts JSON messages with the following actions:

### Authentication
```json
{
  "action": "auth",
  "user_id": 1,
  "username": "john_doe"
}
```

### Join Room
```json
{
  "action": "join",
  "room_id": 5
}
```

### Leave Room
```json
{
  "action": "leave",
  "room_id": 5
}
```

### Send Message
```json
{
  "action": "message",
  "id": 123,
  "body": "Hello, World!",
  "sender": "john_doe",
  "createdAt": "2025-10-28 23:45:00",
  "room_id": 5
}
```

### Ping (Heartbeat)
```json
{
  "action": "ping"
}
```

## Testing WebSocket Connection

A test page is available at `websocket_test.html` to verify the WebSocket connection.

Alternatively, use the Node.js test script:
```bash
cd /tmp
npm install ws
node /path/to/websocket_test.js
```

## Troubleshooting

### Port Already in Use
If port 8080 is already in use, modify the `$port` variable in `websocket/server.php` and update `WS_URL` in `common.js`.

### Connection Refused
Ensure the WebSocket server is running:
```bash
ps aux | grep "websocket/server.php"
```

### Check Server Logs
The server outputs logs to stdout. When running in background, check the log file:
```bash
tail -f websocket.log
```

## Fallback Behavior

If the WebSocket connection fails, the application automatically falls back to HTTP polling every 3 seconds to fetch new messages.

## Security Notes

- The WebSocket server currently runs without SSL/TLS. For production, use `wss://` with proper SSL certificates.
- Authentication is handled through session cookies from the PHP backend.
- Messages are still persisted to the database via the REST API for message history.
