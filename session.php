<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$userId = current_user_id();

if ($userId === null) {
    json_response(['authenticated' => false]);
}

json_response([
    'authenticated' => true,
    'user' => [
        'id' => $userId,
        'username' => $_SESSION['username'] ?? null,
        'screenName' => $_SESSION['screen_name'] ?? null,
    ],
]);
