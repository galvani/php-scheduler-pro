# Testing

Four layers, each proving something the others cannot. Everything runs in Docker with a bundled Redis, or on
the host against a Redis you provide.

| Layer | What it proves | Where |
| --- | --- | --- |
| Unit tests with PHPUnit mocks | each adapter makes exactly the calls it should on its collaborators, and handles every failure (Redis down or answering with an error, lock taken, unknown option, background task) | `tests/**/…UnitTest.php` |
| Unit tests with in-memory fakes | the value objects and their validation, the YAML mapper, `Scheduler`, `LockKey` | `tests/Task/`, `tests/SchedulerTest.php`, `tests/Lock/LockKeyTest.php` |
| Integration tests | the same code against a real Redis, a real Laravel app (Testbench) and real crunz: `schedule:run` runs a command, holds the lock, claims the minute, skips the second server; crunz keeps its lock alive while the task runs; an artisan command outside the schedule never touches Redis | `tests/Lock/RedisMutexTest.php`, `tests/Bridge/*/…Test.php` other than `…UnitTest.php` |
| `composer require` install tests | the package installs into a fresh `laravel/laravel` (13 and 12) app the way a user installs it, and runs a task once across two servers: the marker file holds exactly `server-a` | `docker/install-test.sh` |

A demo application, `demo/laravel`, is also a check: `prove.sh` runs the package's task and the app's own task on two
servers and asserts from the commands' own output files that each wrote exactly one line, from `server-a`
(`== OK: each task ran once, on server-a; server-b skipped both`).

## With Docker

The image is the toolchain only (PHP 8.5, phpredis, Composer); the repository is mounted and every run installs
from `composer.json`. Redis runs as its own service and is not published to the host. Services run as the user
`UID:GID` (default `1000:1000`; export both to match yours), so nothing in the repository ends up owned by root.

```bash
docker compose run --rm tests                    # composer install, then the whole suite
docker compose run --rm tests composer phpstan   # any composer script: phpstan, cs-check, phpmd
docker compose run --rm install-laravel          # fresh Laravel 13 app + composer require
docker compose run --rm install-laravel-12       # fresh Laravel 12 app + composer require
docker compose run --rm demo-laravel             # demo/laravel/prove.sh
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

The suite and PHPStan run on the newest version the constraints allow: Laravel 13. The lower end is covered by the
install test: `install-laravel-12` installs into a fresh Laravel 12 app and runs a task across two servers. There is
no script that runs the whole suite on Laravel 12.
