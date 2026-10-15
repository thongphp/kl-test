# Keyloop

Laravel 13 API MVP for customer vehicle service appointments.

Architecture, concurrency design and technology choices: see [`docs/SYSTEM-DESIGN.md`](docs/SYSTEM-DESIGN.md).

## Stack

PHP 8.5 (Laravel 13 needs PHP 8.3+) · Laravel 13 · PostgreSQL 18 · Redis 7 · Nginx + PHP-FPM · Laravel Sanctum · Dedoc Scramble (OpenAPI) · Pest 4 / PHPUnit 12 · Docker Compose

PHP, PostgreSQL, Redis and Composer all run inside Docker. You do **not** need them installed on your machine.

## Prerequisites

- Git
- Docker Engine 24+ with Docker Compose v2 (`docker compose version`)
- Free host ports: `9000` (API), `5432` (PostgreSQL), `6379` (Redis). If you already run Postgres or Redis locally, change the host side of the port mapping in `docker-compose.yml`.

## Quick Start

```bash
# 1. Clone
git clone https://github.com/thongphp/kl-test.git
cd kl-test

# 2. Create env file (defaults already match docker-compose.yml)
cp .env.example .env

# 3. Build and start containers (app, web, db, redis)
docker compose build
docker compose up -d

# 4. Install PHP dependencies (required: ./ is bind-mounted over the image, so vendor/ is empty)
docker compose exec app composer install

# 5. Generate app key
docker compose exec app php artisan key:generate

# 6. Create tables and seed demo data
docker compose exec app php artisan migrate --seed
```

> Order matters: `.env` must exist before `docker compose up`, and `composer install` must finish before any `php artisan` command.

Verify:

```bash
docker compose ps                      # all services Up / healthy
curl -i http://localhost:9000/up       # health check, expect HTTP 200
```

API base URL: `http://localhost:9000/api/v1`
API docs (Scramble, `APP_ENV=local` only): `http://localhost:9000/docs/api`

## Environment

`.env.example` already works with Docker Compose. Only `APP_KEY` needs generating (step 5).

| Variable | Default | Note |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | Scramble docs are only available in `local`. |
| `APP_URL` | `http://localhost:9000` | Must match the `web` port mapping. |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `db` / `kl_test` / `kl_user` / `secret` | Shared by Laravel and the Postgres container. |
| `REDIS_HOST` / `REDIS_CLIENT` | `redis` / `phpredis` | `CACHE_STORE=redis`. |
| `QUEUE_CONNECTION` | `database` | Run a worker if you use queued jobs (see below). |
| `BOOKING_DB_LOCK_TIMEOUT_MS` | `5000` | Max wait for the booking database lock. |

Never commit `.env` or production secrets.

## Try the API

`php artisan migrate --seed` creates demo data (`database/seeders/DatabaseSeeder.php`):

| Data | Value |
|---|---|
| Dealership | `Demo Dealership` (timezone `UTC`) |
| Technicians / service bays | 3 / 3 |
| Service types | 4 |
| Customer | `customer@example.com`, password `password`, with 2 vehicles |

```bash
# Login -> returns a Sanctum token
curl -s -X POST http://localhost:9000/api/v1/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"customer@example.com","password":"password"}'

# Use the token
curl -s http://localhost:9000/api/v1/appointments \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer <token>"
```

For request bodies (e.g. create appointment), open the API docs at `http://localhost:9000/docs/api`.

Always send `Accept: application/json`, otherwise Laravel may redirect instead of returning JSON errors.

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/auth/login` | Authenticate customer. |
| POST | `/api/v1/appointments` | Create appointment. |
| GET | `/api/v1/appointments` | List customer's appointments. |
| GET | `/api/v1/appointments/{appointment}` | Get appointment. |
| POST | `/api/v1/appointments/{appointment}/cancel` | Cancel appointment. |

Health check (no auth): `GET /up`.

All endpoints except login need `Authorization: Bearer <token>`. The customer comes from the token; do not send `customer_id`.

## Booking Rules (short)

- `end_at = start_at + service_type.duration`
- Time ranges are half-open `[start_at, end_at)`: a booking may start exactly when another ends.
- Overlap: `existing.start_at < requested.end_at AND existing.end_at > requested.start_at`
- Redis helps coordinate; PostgreSQL makes the final decision.

## Tests

```bash
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pest tests/Feature
docker compose exec app php artisan test --coverage    # pcov is installed
```

Tests run on **SQLite in-memory** (set in `phpunit.xml`). No setup needed, and your development PostgreSQL data is never touched.

Limitation: SQLite does not behave like PostgreSQL for row locking and concurrent bookings. Concurrency behaviour needs PostgreSQL tests (planned: a separate PostgreSQL test database).

## Daily Commands

```bash
docker compose up -d                                   # start
docker compose down                                    # stop (keep data)
docker compose exec app php artisan migrate            # run new migrations
docker compose exec app php artisan migrate:fresh --seed   # reset DB (DEV ONLY)
docker compose exec app php artisan route:list
docker compose exec app php artisan about
docker compose exec app php artisan optimize:clear     # clear config/route/cache
docker compose exec app php artisan queue:work         # queue worker, if needed
docker compose exec app composer install               # after composer.json changes
```

Reset everything, including database data:

```bash
docker compose down -v
```

`-v` deletes the Docker volumes (`db_data`, `redis_data`). All local data is lost.

## Troubleshooting

| Problem | Fix |
|---|---|
| `Could not open input file: artisan` / `vendor/autoload.php` not found | Run `docker compose exec app composer install`. |
| `No application encryption key has been specified` | Run `docker compose exec app php artisan key:generate`. |
| `localhost:9000` refused or 502 Bad Gateway | `docker compose ps`, then `docker compose logs -f web app`. Make sure `composer install` was done. |
| `Permission denied` on `storage/` or `bootstrap/cache` | `docker compose exec app chown -R www-data:www-data storage bootstrap/cache` |
| `connection refused` to database | Wait until `db` is healthy (`docker compose ps`). Check `DB_HOST=db` in `.env`. |
| Postgres won't start / port already in use | Stop local Postgres or change `5432:5432` in `docker-compose.yml`. |
| `.env` changes ignored | `docker compose exec app php artisan optimize:clear`, then `docker compose up -d --force-recreate`. |
| `/docs/api` returns 403/404 | Set `APP_ENV=local`, then `php artisan optimize:clear`. |
| Changed `Dockerfile` | `docker compose build --no-cache && docker compose up -d` |

Logs: `docker compose logs -f app` · `web` · `db` · `redis`. Laravel log file: `storage/logs/laravel.log`.
