<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_response(['error' => 'Invalid JSON payload'], 400);
    }

    return is_array($decoded) ? $decoded : [];
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function require_authenticated_user(): int
{
    $userId = current_user_id();
    if ($userId === null) {
        json_response(['error' => 'Authentication required'], 401);
    }

    return $userId;
}

