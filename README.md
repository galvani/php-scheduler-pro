# galvani/php-scheduler-pro

Scheduled tasks for PHP applications that are not tied to one scheduler. Tasks are data: written in YAML or
stored in a database. They run on **Laravel's scheduler** or on **crunz**, under a **reliable Redis lock of the
package's own**, with a **run record** of every start, finish, failure and skip. Swapping the engine changes no
task and no application code.

- One task model for every engine, validated when it is read.
- A Redis mutex with an owner token, plugged into each engine's own lock slots: one run at a time, and one
  server per minute across a cluster.
- An existing Laravel schedule keeps running, gets the same lock without a code change, and moves into the
  package one task at a time with `php artisan scheduling:export`.
- Laravel 12 and 13 through package auto-discovery; Symfony 7.4 and 8 through a bundle, on crunz.

> **On comments:** the code is commented more than production code would be, on purpose, for readability. It is
> a demo meant to be read and presented, so every class, interface and public method says what it is and why it
> exists.

## Requirements

- PHP 8.5 with `ext-redis` (phpredis), and Redis
- `ext-pdo` for tasks stored in a database (optional)
- Laravel 12 or 13, **or** Symfony 7.4 or 8 with `crunzphp/crunz` 3.7+ and `symfony/lock`

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

### Symfony (on crunz)

> **A note on the Symfony integration:** `SchedulingBundle` is the smallest wiring that runs the package in a
> Symfony app, enough to show the engine swap working. It is not the Symfony bundle we would ship as a product:
> that would come with a Flex recipe that registers and configures it on `composer require`, a documented
> configuration reference, autoconfiguration of the application's own services, its own console commands, and
> tests across Symfony versions. Building it out goes beyond the scope of this assignment.

```bash
composer require galvani/php-scheduler-pro crunzphp/crunz symfony/lock
```

Register the bundle and configure it:

```php
// config/bundles.php
Scheduling\Bridge\Symfony\SchedulingBundle::class => ['all' => true],
```

```yaml
# config/packages/scheduling.yaml
scheduling:
    tasks_file: '%kernel.project_dir%/config/scheduling.yaml'
    console_binary: 'php %kernel.project_dir%/bin/console'
    redis: {host: 127.0.0.1, port: 6379}
```

Point crunz at a tasks file that returns the bundle's schedule:

```yaml
# crunz.yml
source: tasks
suffix: Tasks.php
timezone: UTC
```

```php
// tasks/SchedulingTasks.php
use App\Kernel;
use Scheduling\Bridge\Symfony\CrunzTasks;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get(CrunzTasks::class)->schedule();
```

```
* * * * * cd /path/to/app && vendor/bin/crunz schedule:run
```

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
    between: ["06:00", "07:00"]
    environments: [production]
    adapters: {laravel: {output_file: /var/log/reports.log}}
```

| Key | Required | Meaning |
| --- | --- | --- |
| *(the map key)* | yes | the task id: lowercase letters, digits, `.`, `_`, `-` |
| `schedule` | yes | a crontab expression |
| `command` | yes | the console command (`artisan` or `bin/console`) |
| `arguments` | | positional arguments, in order |
| `options` | | `name: value`, `name: [a, b]` for a repeated option, `name: true` for a flag |
| `timezone` | | the timezone the expression is read in; default the application's |
| `max_runtime` | yes | the longest expected run, in seconds; the lock lasts `max_runtime × lock_margin` |
| `lock_margin` | | default `1.5` |
| `enabled` | | `false` keeps the task but does not run it |
| `between` | | run only between two times, `"HH:MM"`, inclusive; may cross midnight |
| `environments` | | run only in these application environments |
| `adapters` | | engine-specific options; `laravel.output_file` appends the command's output to a file |

A row that breaks a rule is reported and skipped; every other task still runs. Every task takes the overlap
lock and runs on one server per minute.

### Tasks in a database

Recurring tasks can also be added, disabled and removed at runtime. Enable the database source
(`SCHEDULING_DATABASE=true` on Laravel, with `php artisan vendor:publish --tag=scheduling-migrations` and
`php artisan migrate`; `scheduling.database` on Symfony), then:

```php
use DateTimeZone;
use Scheduling\SchedulerInterface;
use Scheduling\Task\Command;
use Scheduling\Task\CronExpression;
use Scheduling\Task\LockPolicy;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;

public function __construct(private SchedulerInterface $scheduler) {}

$this->scheduler->schedule(new TaskDefinition(
    new TaskId('invoices-remind'),
    new CronExpression('30 9 * * 1-5'),
    new Command('invoices:remind', ['overdue']),
    new DateTimeZone('Europe/Prague'),
    new LockPolicy(maxRuntimeSeconds: 300),
));

$this->scheduler->disable(new TaskId('invoices-remind'));
$this->scheduler->remove(new TaskId('invoices-remind'));
```

The next tick picks the change up. An id defined in YAML cannot be changed this way
(`TaskNotWritableException`). Outside Laravel, the table is:

```sql
CREATE TABLE scheduler_tasks (
    id VARCHAR(100) PRIMARY KEY,
    definition TEXT NOT NULL   -- the task as JSON, the same shape as a YAML entry
);
```

## An existing Laravel schedule

Entries the application schedules itself in `routes/console.php` keep running as they are, and they get the
package's Redis lock too: no `cache:clear` wipes it, and one server runs each minute. Tasks scheduled with
`runInBackground()` stay on Laravel's own lock.

To move an entry into the package, print the schedule as YAML:

```bash
php artisan scheduling:export
```

Each mappable entry comes out ready for `scheduling.yaml`, with review notes for what changes; closures, jobs,
shell commands and sub-minute entries are listed as not exported. Paste the task and delete its PHP line in the
same deploy, or it runs twice.

## Configuration

**Laravel**, `config/scheduling.php`:

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `tasks_file` | `SCHEDULING_TASKS_FILE` | `base_path('scheduling.yaml')` | the YAML tasks file |
| `redis` | `SCHEDULING_REDIS_CONNECTION` | `default` | the Laravel Redis connection to read host, credentials and database from; phpredis; the app's key prefix is not applied |
| `host` | `SCHEDULING_HOST` | the machine's hostname | the name recorded with each run |
| `database.enabled` | `SCHEDULING_DATABASE` | `false` | read and write tasks in a table too |
| `database.connection` | | the default connection | the Laravel database connection |
| `database.table` | | `scheduler_tasks` | the table |

**Symfony**, `scheduling:` in `config/packages/scheduling.yaml`: `tasks_file` and `console_binary` (required),
`host`, `redis.host`, `redis.port`, and `database.enabled`, `dsn`, `user`, `password`, `table`.

## Engines

Each engine reports how it fulfils each capability: natively, polyfilled by the package, or not at all. A task
that needs an unavailable capability is refused instead of silently losing behaviour.

| Capability | Laravel | crunz |
| --- | --- | --- |
| One run at a time (overlap lock) | native, our mutex in Laravel's slot | native, our mutex as crunz's lock store |
| One server per minute | native, our mutex in Laravel's slot | polyfilled by the package |
| Conditions (`between`, `environments`) | native | native |
| Run record | polyfilled | polyfilled |
| More often than once a minute | unavailable | unavailable |
| Catch-up of missed runs | unavailable | unavailable |

Every engine takes the same lock keys, so servers on different engines exclude each other. The next engine
worth adding is Symfony's Scheduler; see [docs/design.md](docs/design.md#the-next-engine-symfony-scheduler).

## Demos

| | Run | What it shows |
| --- | --- | --- |
| [`demo/laravel`](demo/laravel) | `docker compose run --rm demo-laravel` | a Laravel 13 app: a YAML task and the app's own entry, two servers in one minute, `scheduling:export` |
| [`demo/symfony`](demo/symfony) | `docker compose run --rm demo` | a Symfony 8 app on crunz: the capability report, two servers in one minute |

## Development

```bash
docker compose run --rm tests                 # the whole suite against a bundled Redis
docker compose run --rm install-laravel       # composer require into a fresh Laravel app
```

Unit tests with mocks, integration tests against Redis and a real Laravel app, and `composer require` install
tests on Laravel 12, 13 and Symfony: see [docs/testing.md](docs/testing.md). The pre-commit hook runs the checks
in Docker when it is available.

## Limitations

- Laravel's `runInBackground()` is not supported for the package's tasks, and the application's own background
  tasks keep Laravel's lock. Our lock is released only by the process that took it; Laravel releases a
  background run from another process.
- On Laravel a lock cannot be extended while a task runs; a run longer than its lock is recorded as `overran`.
  crunz extends it.
- crunz has no event for a run skipped because the previous one is still running, so that skip is not recorded.
- Laravel's failure hook gives no exit code or exception, so a failed run on Laravel has no reason recorded.
- The application's own Laravel tasks get the lock but no run record, until they are exported into the package.
- Only `between` and `environments` can be written as data; a custom condition is code.
- The Symfony bundle is minimal wiring, not a full product bundle (see the note under Installation); there is
  no Flex recipe, so the bundle and its config are added by hand.

Why each of these is so, and what is designed but not built: [docs/design.md](docs/design.md).

## About

Written as the answer to a hiring assignment (the brief is in [`ASSIGNMENT.md`](ASSIGNMENT.md)): interfaces for
a Scheduler and a Mutex so that the Laravel implementation can be swapped. The reasoning is in
[docs/design.md](docs/design.md).

## License

Proprietary.
