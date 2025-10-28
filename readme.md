# Box Chat
A simple web-based chat application built with PHP, Bootstrap, and WebSocket for real-time messaging.

### Configure database access

Set the following environment variables before starting Apache/PHP so the API can talk to MySQL (or MariaDB):

| Variable | Purpose | Default |
| -------- | ------- | ------- |
| `DB_HOST` | Database host | `127.0.0.1` |
| `DB_PORT` | Database port | `3306` |
| `DB_NAME` | Schema name | `436db` |
| `DB_USER` | Database user | `root` |
| `DB_PASSWORD` | Database user password | *(empty)* |

### Initialize database schema

Run the statements in `database.sql` to create the `users`, `list_of_chatrooms`, `messages`, and related tables used by the API. You can pipe the file directly into `mysql`:

```bash
mysql -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < database.sql
```

### Run locally with Docker (PHP + Apache)

```bash
docker run -d \
  -p 8080:80 \
  --name my-apache-php-app \
  -e DB_HOST="host.docker.internal" \
  -e DB_PORT="3306" \
  -e DB_NAME="436db" \
  -e DB_USER="root" \
  -e DB_PASSWORD="your_password" \
  -v "$PWD":/var/www/html \
  php:8.3-apache
```

Navigate to `http://localhost:8080` after the container starts.

### Start the WebSocket Server

For real-time messaging, start the WebSocket server on port 8080:

```bash
composer install
php websocket/server.php
```

See [WEBSOCKET_README.md](WEBSOCKET_README.md) for detailed WebSocket setup and configuration.

### What's implemented

- User registration (`api/signup.php`) validates input, hashes passwords, and writes to `users`.
- Login (`api/login.php`) checks hashed passwords and establishes a PHP session consumed by the frontend.
- Room list (`api/rooms.php`) pulls from `list_of_chatrooms`. No default rooms are auto-created.
- Message flow (`api/messages.php`) persists chat messages and exposes them via REST endpoints.
- **Real-time messaging via WebSocket** (`websocket/server.php`) for instant message delivery without polling.

### Smoke-testing checklist

1. Hit `/api/session.php` in a browser or with `curl` to confirm authentication state.
2. Register a user from `signup.php`, then log in from `login.php`.
3. Start the WebSocket server: `php websocket/server.php`
4. Open the browser console while on `index.php` to confirm WebSocket connection.
5. Use two browser windows to log in as different users; join the same room and verify messages appear instantly via WebSocket.
