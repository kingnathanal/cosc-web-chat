<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = read_json_input();
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    json_response(['error' => 'Username and password are required'], 422);
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id, username, screenName, password FROM users WHERE username = :username');
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();


if (!$user || $password !== $user['password']) {
    json_response(['error' => 'Invalid credentials'], 401);
}

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['screen_name'] = $user['screenName'];

json_response([
    'message' => 'Login successful',
    'user' => [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'screenName' => $user['screenName'],
    ],
]);
