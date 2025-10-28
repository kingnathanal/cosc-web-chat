# Box Chat
A simple web-based chat application built with PHP, Bootstrap, and a lightweight PHP API.

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

### Start the WebSocket relay

Chat updates now flow through a lightweight WebSocket relay backed by `ws_server.php`. Start it alongside Apache/PHP:

```bash
php ws_server.php            # binds to 0.0.0.0:8090 by default
# or provide an explicit bind address / port
php ws_server.php 127.0.0.1:9000
```

The frontend requests connection tokens from `/api/socket_token.php` and will attempt to connect to:

- `WS_ENDPOINT` – if this environment variable is set (e.g., `ws://chat.example.com:9000`)
- Otherwise `ws(s)://<HTTP_HOST>:WS_PORT`, where `WS_PORT` defaults to `8090`

Expose the relay port through your container or reverse proxy so browsers can reach it.

### What’s implemented

- User registration (`api/signup.php`) validates input, hashes passwords, and writes to `users`.
- Login (`api/login.php`) checks hashed passwords and establishes a PHP session consumed by the frontend.
- Room list (`api/rooms.php`) pulls from `list_of_chatrooms`. No default rooms are auto-created.
- Real-time message flow: `/api/socket_token.php` issues short-lived tokens, and `ws_server.php` accepts WebSocket connections, persists messages/DMs, and broadcasts updates immediately. `/api/messages.php` and `/api/dm.php` remain for history/initial state.

### Smoke-testing checklist

1. Hit `/api/session.php` in a browser or with `curl` to confirm authentication state.
2. Register a user from `signup.php`, then log in from `login.php`.
3. Open the browser console (Network tab) on `index.php` to confirm `/api/socket_token.php` is hit and a `ws://` connection is established.
4. Use two browser windows to log in as different users; join the same room and verify messages/DMs appear instantly through the socket relay.
