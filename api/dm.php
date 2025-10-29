<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// Deprecated. Direct messages are now handled by the WebSocket server
// (simple_ws_server.php). Keep this endpoint returning 410 to avoid
// accidental usage by older clients.

json_response([
    'error' => 'deprecated',
    'message' => 'Direct messages are served via WebSocket; connect to the socket server and obtain a token from api/socket_token.php'
], 410);
