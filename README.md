# DANA Payment Gateway — Laravel Docker (External MySQL)

Copy `compose.yml`, `docker/`, and `.dockerignore` into the root of an existing Laravel project.

## Development

1. In `.env`, configure the external MySQL connection:

```dotenv
APP_PORT=8080
DB_CONNECTION=mysql
DB_HOST=host.docker.internal
DB_PORT=3306
DB_DATABASE=dana_payment
DB_USERNAME=dana_app
DB_PASSWORD=replace_with_secure_password
```

`host.docker.internal` refers to the Docker host, not another LAN server. If MySQL runs on a different server, set `DB_HOST` to its reachable private IP/DNS name instead. Configure MySQL grants and firewall to allow the container's network. Do not expose MySQL publicly.

2. Build and start:

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

3. Test `http://localhost:8080` (on the Docker host). The Nginx port is bound to localhost only; use a host reverse proxy with HTTPS for public DANA webhooks.

## Production notes

This is a development-oriented bind-mount setup. For production, use an immutable image, non-root execution, secured secrets, health checks, backup/restore plans, and HTTPS reverse proxy. Make sure `storage/` and `bootstrap/cache/` are writable by the PHP process. Never commit `.env` or private keys. Run `php artisan key:generate` only once for a new app and preserve APP_KEY afterward.
