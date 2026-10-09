# Testing

Four layers, each proving something the others cannot. Everything runs in Docker with a bundled Redis, or on
the host against a Redis you provide.

| Layer | What it proves | Where |
| --- | --- | --- |
| Unit tests with PHPUnit mocks | each adapter makes exactly the calls it should on its collaborators, and handles every failure (Redis down, lock taken, unknown option, background task) | `tests/**/…UnitTest.php`, `tests/Bridge/**/…Test.php` |
| Integration tests | the same code against a real Redis and a real Laravel app (Testbench): `schedule:run` runs a command, holds the lock, claims the minute, skips the second server | `tests/Bridge/Laravel/`, `tests/Lock/`, `tests/Run/`, `tests/Task/` |
| `composer require` install tests | the package installs into a fresh `laravel/laravel` (13 and 12) and `symfony/skeleton` app the way a user installs it, and runs a task once across two servers | `docker/install-test.sh` |
| Demos | two complete applications to read and run | `demo/laravel`, `demo/symfony` |

## With Docker

The image is the toolchain only (PHP 8.5, phpredis, Composer); the repository is mounted and every run installs
from `composer.json`. Redis runs as its own service and is not published to the host. Services run as your user,
so nothing in the repository ends up owned by root.

```bash
docker compose run --rm tests                    # composer install, then the whole suite
docker compose run --rm tests composer phpstan   # any composer script: cs-check, phpstan, phpmd
docker compose run --rm install-laravel          # fresh Laravel 13 app + composer require
docker compose run --rm install-laravel-12       # fresh Laravel 12 app + composer require
docker compose run --rm install-symfony          # fresh Symfony skeleton + composer require, on crunz
docker compose run --rm demo-laravel             # demo/laravel/prove.sh
docker compose run --rm demo                     # demo/symfony/prove.sh
docker compose down                              # stop Redis
```

## On the host

```bash
docker run -d --rm --name scheduler-redis -p 127.0.0.1:16379:6379 redis:7-alpine
composer install
composer test        # Redis at REDIS_HOST:REDIS_PORT, default 127.0.0.1:16379
composer phpstan     # level max over src and tests, checked exceptions
composer cs-check    # php-cs-fixer, @Symfony plus the project's rules
```

A Redis test fails, never skips, when Redis is unreachable: a suite that skips its Redis tests passes while
proving nothing about the lock.

## The pre-commit hook

`composer install` points git at `.githooks/`. With Docker running, the hook runs `cs-check`, PHPStan and the
whole suite in the container; without Docker, `cs-check` and PHPStan on the host. A commit that fails is
refused.

## Version range

Both ends of every constraint were run: Laravel 12.69 and 13.35, Symfony 7.4 and 8.1, the whole suite and
PHPStan on each.
