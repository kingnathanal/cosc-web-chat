<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = read_json_input();

$firstName = trim($input['firstName'] ?? '');
$lastName = trim($input['lastName'] ?? '');
$username = trim($input['username'] ?? '');
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$passwordConfirm = $input['passwordConfirm'] ?? '';

$errors = [];

if ($firstName === '') {
    $errors['firstName'] = 'First name is required';
}

if ($lastName === '') {
    $errors['lastName'] = 'Last name is required';
}

if ($username === '') {
    $errors['username'] = 'Username is required';
} elseif (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
    $errors['username'] = 'Username must be 3-30 characters and contain only letters, numbers, or _.-';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Valid email is required';
}

if (strlen($password) < 8) {
    $errors['password'] = 'Password must be at least 8 characters';
}

if ($password !== $passwordConfirm) {
    $errors['passwordConfirm'] = 'Passwords do not match';
}

if (!empty($errors)) {
    json_response(['error' => 'Validation failed', 'fields' => $errors], 422);
}

$pdo = get_db();

$screenName = trim(sprintf('%s %s', $firstName, $lastName));
if ($screenName === '') {
    $screenName = $username;
}

$storedPassword = $password;

try {
    $stmt = $pdo->prepare('INSERT INTO users (username, screenName, password) VALUES (:username, :screenName, :password)');
    $stmt->execute([
        ':username' => $username,
        ':screenName' => $screenName,
        ':password' => $storedPassword,
    ]);
} catch (PDOException $e) {
    if ((int) $e->getCode() === 23000) {
        json_response(['error' => 'Username already exists'], 409);
    }

    json_response(['error' => 'Failed to create user', 'details' => $e->getMessage()], 500);
}

json_response(['message' => 'Account created successfully']);
