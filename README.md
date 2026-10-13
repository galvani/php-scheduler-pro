# galvani/php-scheduler-pro

Scheduled tasks for PHP applications that are not tied to one scheduler. Tasks are data, written in YAML. They
run on **Laravel's scheduler** or on **crunz**, under a **reliable Redis lock of the package's own**. Swapping the
engine changes no task and no application code.

> **This branch is the assignment's required minimum** ([`ASSIGNMENT.md`](ASSIGNMENT.md)): the Scheduler and Mutex
> interfaces, a Laravel implementation that runs tasks on a crontab expression, our own mutex in place of
> Laravel's, and a crunz implementation that proves the swap. The `full` branch builds on the
> same interfaces and adds a run record, tasks in a database, a capability report, run conditions, a Symfony
> bundle and `scheduling:export`.

The interfaces, with every parameter and the reason for its shape, are in
[docs/design.md](docs/design.md#the-interfaces).

- One task model for every engine, validated when it is read.
- A Redis mutex with an owner token, plugged into each engine's own lock slots: one run at a time, and one
  server per minute across a cluster.
- An existing Laravel schedule keeps running and gets the same lock without a code change.
- Laravel 12 and 13 through package auto-discovery; crunz 3.7+ wired in a few lines of PHP. Both are optional.

> **On comments:** the code is commented more than production code would be, on purpose: it is the answer to a
> hiring test and is meant to be read and presented, so every class, interface and public method says what it is
> and why it exists. A method that implements an interface or overrides a parent deliberately repeats the parent's
> documentation instead of `{@inheritDoc}`, so a reader sees the promise without opening the parent.

## Requirements

- PHP 8.5 with `ext-redis` (phpredis), and a Redis server: the shipped mutex is Redis

Nothing else. The integrations are optional; install the one your application uses:

| Integration | Supported versions | What to install |
| --- | --- | --- |
| Laravel's scheduler | Laravel 12, 13 | nothing more: the service provider is discovered automatically |
| crunz | crunz 3.7+ | `composer require crunzphp/crunz` (it brings `symfony/lock`) |

## Installation

### Laravel

```bash
composer require galvani/php-scheduler-pro
php artisan vendor:publish --tag=scheduling-config
```

The service provider is discovered automatically. Keep Laravel's usual crontab line:

```
* * * * * php /path/to/app/artisan schedule:run
```

### crunz

```bash
composer require galvani/php-scheduler-pro crunzphp/crunz
```

crunz reads its tasks from PHP files that return a `Schedule`. One file hands it the package's tasks:

```php
// tasks/SchedulingTasks.php
use Crunz\Schedule;
use Psr\Log\NullLogger;
use Scheduling\Bridge\Crunz\CrunzEngine;
use Scheduling\Lock\RedisMutex;
use Scheduling\Scheduler;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\YamlTaskSource;

require dirname(__DIR__).'/vendor/autoload.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$schedule = new Schedule();
$engine = new CrunzEngine($schedule, new RedisMutex($redis), 'php '.dirname(__DIR__).'/bin/console', new NullLogger());
$tasks = new YamlTaskSource(dirname(__DIR__).'/scheduling.yaml', new TaskDefinitionMapper(new DateTimeZone('UTC')));
new Scheduler($tasks)->registerWith($engine);

return $schedule;
```

```yaml
# crunz.yml
source: tasks
suffix: Tasks.php
timezone: UTC
```

```
* * * * * cd /path/to/app && vendor/bin/crunz schedule:run
```

`registerWith()` returns the tasks that did not register as problems; log them in a real application.

## Defining tasks

Tasks live in a YAML file (`scheduling.yaml` in a Laravel app's root by default):

```yaml
tasks:
  reports-send:
    schedule: "0 6 * * *"
    command: reports:send
    arguments: [weekly]
    options: {queue: high, force: true}
    timezone: Europe/Prague
    max_runtime: 600
    adapters: {laravel: {output_file: /var/log/reports.log}}
```

| Key | Required | Meaning |
| --- | --- | --- |
| *(the map key)* | yes | the task id: lowercase letters, digits, `.`, `_`, `-`; starts with a letter or digit; at most 100 characters |
| `schedule` | yes | a crontab expression |
| `command` | yes | the console command (`artisan` or `bin/console`): letters, digits and `: _ . -` |
| `arguments` | | positional arguments, in order; any text, quoted by the engine |
| `options` | | `name: value`, `name: [a, b]` for a repeated option, `name: true` for a flag; names are letters, digits, `_` and `-`, starting with a letter; values are any text, quoted by the engine |
| `timezone` | | the timezone the expression is read in; default `app.timezone` on Laravel, the mapper's default timezone on crunz |
| `max_runtime` | yes | the longest expected run, in whole seconds, at least 1 |
| `lock_margin` | | a number of at least `1.0`, default `1.5`; on Laravel the overlap lock lasts `max_runtime × lock_margin` |
| `enabled` | | `false` keeps the task but does not run it |
| `adapters` | | engine-specific options; `laravel.output_file` appends the command's output to a file; crunz takes none |

A row that breaks a rule is reported and skipped; every other task still runs. Every task takes the overlap
lock and runs on one server per minute.

## An existing Laravel schedule

Entries the application schedules itself in `routes/console.php` keep running as they are, and they get the
package's Redis lock too: no `cache:clear` wipes it, and one server runs each minute. Tasks scheduled with
`runInBackground()` stay on Laravel's own lock, in the store `cache.schedule_store` names, as without the package.

## Configuration

**Laravel**, `config/scheduling.php`:

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `tasks_file` | `SCHEDULING_TASKS_FILE` | `base_path('scheduling.yaml')` | the YAML tasks file |
| `redis` | `SCHEDULING_REDIS_CONNECTION` | `scheduler` | the Laravel Redis connection the scheduler uses (phpredis). `scheduler` is added for you as a copy of the app's `default` connection without its key prefix; a connection with a prefix is refused, because the prefix would land on every lock key |

Only the artisan commands that read the schedule (`schedule:run`, `schedule:list`, `schedule:test`,
`schedule:finish`, `schedule:clear-cache`) load the tasks and connect to the scheduler's Redis. A misconfigured
connection fails those commands and never `migrate`, `config:cache` or a deploy script.

When Redis cannot be reached or answers with an error, a run that needs a lock is skipped rather than run
unlocked, and a warning naming the task is logged.

## Engines

An engine is the scheduler that decides what is due and runs it: Laravel's scheduler or crunz. A bridge is this
package's code for one engine (`src/Bridge/…`), and `adapters:` in a task holds per-engine options.

| | Laravel | crunz |
| --- | --- | --- |
| One run at a time (overlap lock) | our mutex in Laravel's slot | our mutex as crunz's lock store |
| One server per minute | our mutex in Laravel's slot | a per-minute claim the package adds |

Every engine takes the same lock keys, so servers on different engines exclude each other. The overlap lock
lives differently on each: on Laravel it lasts `max_runtime × lock_margin` and cannot be extended while the task
runs; on crunz it lasts two minutes and crunz extends it for as long as the task runs, so `lock_margin` has no
effect there. The next engine worth adding is Symfony's Scheduler; see
[docs/design.md](docs/design.md#the-next-engine-symfony-scheduler).

## Demo

| | Run | What it shows |
| --- | --- | --- |
| [`demo/laravel`](demo/laravel) | `docker compose run --rm demo-laravel` | a Laravel 13 app: a YAML task and the app's own entry, two servers in one minute, each task run once |

## Development

```bash
docker compose run --rm tests                 # the whole suite against a bundled Redis
docker compose run --rm install-laravel       # composer require into a fresh Laravel app
```

Unit tests with mocks, integration tests against Redis, a real Laravel app and real crunz, and
`composer require` install tests on Laravel 12 and 13: see [docs/testing.md](docs/testing.md). The pre-commit hook
runs the checks in Docker when it is available.

## Limitations

- The lock is as safe as the single Redis node it lives on. A failover to a replica that had not received the
  latest `SET` can hand the same lock to a second server; Redlock over several independent nodes, or a store with
  consensus, would close it. See the trade-offs in [docs/design.md](docs/design.md#known-trade-offs).
- Laravel's `runInBackground()` is not supported for the package's tasks, and the application's own background
  tasks keep Laravel's lock. Our lock is released only by the process that took it; Laravel releases a
  background run from another process.
- On Laravel a lock cannot be extended while a task runs; a run longer than `max_runtime × lock_margin` may lose
  its lock.
- `schedule:clear-cache` clears only Laravel's own locks. A lock of the package expires on its TTL; a
  killed run of an app task with the default `withoutOverlapping()` holds its key for 24 hours.

Why each of these is so: [docs/design.md](docs/design.md).

## About

Written as the answer to a hiring assignment (the brief is in [`ASSIGNMENT.md`](ASSIGNMENT.md)): interfaces for
a Scheduler and a Mutex so that the Laravel implementation can be swapped. The reasoning is in
[docs/design.md](docs/design.md).

## License

Proprietary.
