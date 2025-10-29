<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// This endpoint has been deprecated. Presence is now handled by the
// WebSocket server (`simple_ws_server.php`). Keep this file as an explicit
// 410 response to avoid accidental usage by older clients.

json_response(['error' => 'deprecated', 'message' => 'Presence endpoint removed; use WebSocket connection'], 410);
