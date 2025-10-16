<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

// Server-Sent Events stream for room updates
// Uses a simple polling loop server-side to detect changes and notifies clients.

// Only authenticated users can subscribe
$userId = require_authenticated_user();
// Important: release session lock so other requests (e.g., messages, rooms) aren't blocked
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
set_time_limit(0);
ignore_user_abort(true);

// Override JSON header sent by bootstrap
header_remove('Content-Type');
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$pdo = get_db();

// Helper to flush
$flush = static function (): void {
    // End any buffering layers and flush
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
};

// Establish a baseline state
$stmt = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(created_at), '1970-01-01 00:00:00') AS m FROM list_of_chatrooms");
$row = $stmt->fetch();
$lastCount = (int)($row['c'] ?? 0);
$lastMax = (string)($row['m'] ?? '1970-01-01 00:00:00');

// Advise browser on reconnect delay and send an initial ping
echo "retry: 2000\n";
// Padding to kick-start streaming in some environments
echo ": init\n\n";
echo "event: ping\n";
echo "data: connected\n\n";
$flush();

$start = time();
$timeout = 300; // keep stream for up to 5 minutes per connection

while (!connection_aborted() && (time() - $start) < $timeout) {
    // Sleep briefly to avoid hammering DB
    usleep(800000); // 0.8s

    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(created_at), '1970-01-01 00:00:00') AS m FROM list_of_chatrooms");
        $row = $stmt->fetch();
        $count = (int)($row['c'] ?? 0);
        $max = (string)($row['m'] ?? '1970-01-01 00:00:00');
    } catch (Throwable $e) {
        // Emit an error and stop
        echo "event: error\n";
        echo 'data: ' . json_encode(['message' => 'db_error']) . "\n\n";
        $flush();
        break;
    }

    if ($count !== $lastCount || $max !== $lastMax) {
        $lastCount = $count;
        $lastMax = $max;
        echo "event: rooms_update\n";
        echo 'data: {"changed":true}' . "\n\n";
        $flush();
    } else {
        // Heartbeat comment to keep connection alive
        echo ": hb\n\n";
        $flush();
    }
}

// Tell client to reconnect
echo "event: end\n";
echo "data: bye\n\n";
$flush();
