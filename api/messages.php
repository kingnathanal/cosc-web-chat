<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// Deprecated. Message retrieval and posting is now handled by the WebSocket
// server (simple_ws_server.php). Keep this endpoint returning 410 to make
// accidental usage explicit and to avoid silent failures by old clients.

json_response([
    'error' => 'deprecated',
    'message' => 'Messages are served via WebSocket; connect to the socket server and obtain a token from api/socket_token.php'
], 410);
