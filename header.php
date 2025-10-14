<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Box Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
</head>
<body>
    <nav class="navbar bg-body-tertiary py-4" data-bs-theme="dark">
        <div class="container-fluid">
            <div>
                <a class="navbar-brand fw-bold" href="./index.php">The Box Web-Chat</a><br />
                <span class="navbar-text fs-6">By William Britton & Sai Mani Kiran Bandi</span>
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Basic example">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#helpModal">Help</button>
                <button type="button" class="btn btn-outline-primary signup" onclick="toSignup()">Sign Up</button>
                <button type="button" class="btn btn-outline-primary login" onclick="toLogin()">Login</button>
                <button type="button" class="btn btn-outline-primary logout" style="display:none;" onclick="logout()">Logout</button>
            </div>
        </div>
    </nav>
    <div class="container mt-5">
