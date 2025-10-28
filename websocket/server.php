#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use BoxChat\WebSocket\ChatServer;

$port = 8080;

echo "Starting WebSocket server on port {$port}...\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ChatServer()
        )
    ),
    $port
);

echo "WebSocket server running at ws://localhost:{$port}\n";
echo "Press Ctrl+C to stop the server.\n";

$server->run();
