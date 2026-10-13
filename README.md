# galvani/php-scheduler-pro

Scheduled tasks for PHP applications that are not tied to one scheduler. Tasks are data: written in YAML or
stored in a database. They run on **Laravel's scheduler** or on **crunz**, under a **reliable Redis lock of the
package's own**, with a **run record** of every start, finish, failure and skip. Swapping the engine changes no
task and no application code.

The interfaces, with every parameter and the reason for its shape, are in
[docs/design.md](docs/design.md#the-interfaces).

- One task model for every engine, validated when it is read.
- A Redis mutex with an owner token, plugged into each engine's own lock slots: one run at a time, and one
  server per minute across a cluster.
- An existing Laravel schedule keeps running, gets the same lock without a code change, and moves into the
  package one task at a time with `php artisan scheduling:export`.
- Laravel 12 and 13 through package auto-discovery; crunz in a Symfony 7.4 or 8 app through a bundle. Both are
  optional.

> **On comments:** the code is commented more than production code would be, on purpose, for readability. It is
> a demo meant to be read and presented, so every class, interface and public method says what it is and why it
> exists.

## Requirements

- PHP 8.5 with `ext-redis` (phpredis), and a Redis server: the shipped mutex and run record are Redis
- `ext-pdo`, only for tasks stored in a database

Nothing else. The integrations are optional; install the one your application uses:

| Integration | Supported versions | What to install |
| --- | --- | --- |
| Laravel's scheduler | Laravel 12, 13 | nothing more: the service provider is discovered automatically |
| crunz, in a Symfony app | Symfony 7.4, 8; crunz 3.7+ | `composer require crunzphp/crunz` (it brings `symfony/lock`) |

Asking for the crunz engine without its packages fails with a `MissingPackageException` that names the
`composer require` to run.

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
composer require galvani/php-scheduler-pro crunzphp/crunz
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
    redis: '%env(REDIS_URL)%' # the app's own Redis: a DSN such as redis://127.0.0.1:6379, or '@app.redis'
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
| *(the map key)* | yes | the task id: lowercase letters, digits, `.`, `_`, `-`; starts with a letter or digit; at most 100 characters |
| `schedule` | yes | a crontab expression |
| `command` | yes | the console command (`artisan` or `bin/console`): letters, digits and `: _ . -` |
| `arguments` | | positional arguments, in order; any text, quoted by the engine |
| `options` | | `name: value`, `name: [a, b]` for a repeated option, `name: true` for a flag; names are letters, digits, `_` and `-`, starting with a letter; values are any text, quoted by the engine |
| `timezone` | | the timezone the expression is read in; default `app.timezone` on Laravel, PHP's default timezone on Symfony |
| `max_runtime` | yes | the longest expected run, in whole seconds, at least 1; a run that lasts longer is recorded `overran` |
| `lock_margin` | | a number of at least `1.0`, default `1.5`; on Laravel the overlap lock lasts `max_runtime × lock_margin` |
| `enabled` | | `false` keeps the task but does not run it |
| `between` | | run only between two times, `"HH:MM"`, inclusive; may cross midnight |
| `environments` | | run only in these application environments |
| `adapters` | | engine-specific options; `laravel.output_file` appends the command's output to a file; crunz takes none |

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

final class InvoiceReminders
{
    public function __construct(private SchedulerInterface $scheduler) {}

    public function start(): void
    {
        $this->scheduler->schedule(new TaskDefinition(
            new TaskId('invoices-remind'),
            new CronExpression('30 9 * * 1-5'),
            new Command('invoices:remind', ['overdue']),
            new DateTimeZone('Europe/Prague'),
            new LockPolicy(maxRuntimeSeconds: 300),
        ));
    }

    public function pause(): void
    {
        $this->scheduler->disable(new TaskId('invoices-remind'));
    }

    public function stop(): void
    {
        $this->scheduler->remove(new TaskId('invoices-remind'));
    }
}
```

The next tick picks the change up. `schedule()` only adds: an id that is already scheduled throws
`TaskConflictException`, and the database decides it, so two processes adding the same id get one success and
one conflict. To change a task, remove it and schedule it again. `disable()` and `remove()` throw
`TaskNotFoundException` for an id that is not scheduled. An id defined in YAML cannot be changed this way
(`TaskNotWritableException`), and neither can any id while the YAML file cannot be read or reports that id
invalid: the YAML file might own it.

Only a second row with the same id is a conflict; any other database error is a `StorageException`. Inside the
caller's own transaction the insert runs in a savepoint, so a conflict leaves the transaction usable on
PostgreSQL too. When the database cannot be reached, the YAML tasks still run, a warning is logged, and writes
throw `TaskNotWritableException`. Outside Laravel, the table is:

```sql
CREATE TABLE scheduler_tasks (
    id VARCHAR(100) PRIMARY KEY,
    definition TEXT NOT NULL   -- the task as JSON, the same shape as a YAML entry
);
```

## An existing Laravel schedule

Entries the application schedules itself in `routes/console.php` keep running as they are, and they get the
package's Redis lock too: no `cache:clear` wipes it, and one server runs each minute. Tasks scheduled with
`runInBackground()` stay on Laravel's own lock, in the store `cache.schedule_store` names, as without the package.

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
| `redis` | `SCHEDULING_REDIS_CONNECTION` | `scheduler` | the Laravel Redis connection the scheduler uses (phpredis). `scheduler` is added for you as a copy of the app's `default` connection without its key prefix; a connection with a prefix is refused, because the prefix would land on every lock key |
| `host` | `SCHEDULING_HOST` | the machine's hostname, read when the app runs (a cached config does not freeze it); `unknown` if it has none | the name recorded with each run |
| `database.enabled` | `SCHEDULING_DATABASE` | `false` | read and write tasks in a table too |
| `database.connection` | | the default connection | the Laravel database connection |
| `database.table` | | `scheduler_tasks` | the table |

Only the artisan commands that read the schedule (`schedule:run`, `schedule:list`, `schedule:test`,
`schedule:finish`, `schedule:clear-cache`, `scheduling:export`) load the tasks and connect to the scheduler's
Redis. A misconfigured connection fails those commands and never `migrate`, `config:cache` or a deploy script.

**Symfony**, `scheduling:` in `config/packages/scheduling.yaml`:

| Key | Default | Meaning |
| --- | --- | --- |
| `tasks_file` | required | the YAML tasks file |
| `console_binary` | required | how crunz runs a command, e.g. `php %kernel.project_dir%/bin/console` |
| `redis` | required | the app's own Redis: a DSN `redis[s]://[user:password@]host[:port][/db]`, usually `'%env(REDIS_URL)%'`, or `'@service_id'` for a phpredis `Redis` service without a key prefix |
| `host` | the machine's hostname, read when the app runs; `unknown` if it has none | the name recorded with each run |
| `database.enabled` | `false` | read and write tasks in a table too |
| `database.dsn` | required when enabled | the PDO DSN |
| `database.user`, `database.password` | none | the PDO credentials |
| `database.table` | `scheduler_tasks` | the table |

## The run record

Every start, finish, failure and skip of the package's tasks is appended to a shared history in Redis, one list
per task (`scheduler:runs:<id>`, the newest 1000 entries), so "did it run, where, and how did it end" has one
answer across servers. Each entry carries the state, the time, the server, and a detail: the error or exit code
of a failure, the reason of a skip. A start and its end carry the same run id.

| State | Meaning |
| --- | --- |
| `started` | the run began, holding its locks |
| `finished` | it ended successfully within `max_runtime` |
| `failed` | it ended with an error within `max_runtime` |
| `overran` | it ended, either way, after more than `max_runtime` from its own start: its lock may have expired while it ran |
| `skipped` | it was due and did not start |

| Skip reason | Meaning |
| --- | --- |
| `still_running` | the previous run still holds the overlap lock |
| `ran_on_another_server` | another server claimed this minute |
| `lock_unavailable` | Redis could not be reached or answered with an error; the run is skipped rather than run unlocked |
| `condition_not_met` | `between` or `environments` held it back |
| `paused` | the Laravel schedule is paused (`schedule:pause`, Laravel 13), or no other cause could be found |

`RunRepositoryInterface::lastOf()` reads the newest entry of a task in a state. Nothing in the package reads the
history back; this is the read side for monitoring and dashboards.

## Engines

An engine is the scheduler that decides what is due and runs it: Laravel's scheduler or crunz. A bridge is this
package's code for one engine (`src/Bridge/…`), and `adapters:` in a task holds per-engine options.

Each engine reports how it fulfils each capability: natively, polyfilled by the package, or not at all. A task
that needs an unavailable capability is refused instead of silently losing behaviour; today the only capability
a task can need is conditions, which both engines fulfil natively, and the rest of the report is information.

| Capability | Laravel | crunz |
| --- | --- | --- |
| One run at a time (overlap lock) | native, our mutex in Laravel's slot | native, our mutex as crunz's lock store |
| One server per minute | native, our mutex in Laravel's slot | polyfilled by the package |
| Conditions (`between`, `environments`) | native | native |
| Run record | polyfilled | polyfilled |
| More often than once a minute | unavailable | unavailable |
| Catch-up of missed runs | unavailable | unavailable |

Every engine takes the same lock keys, so servers on different engines exclude each other. The overlap lock
lives differently on each: on Laravel it lasts `max_runtime × lock_margin` and cannot be extended while the task
runs; on crunz it lasts two minutes and crunz extends it for as long as the task runs, so `lock_margin` has no
effect there. The next engine worth adding is Symfony's Scheduler; see [docs/design.md](docs/design.md#the-next-engine-symfony-scheduler).

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
- On Laravel a lock cannot be extended while a task runs. A run longer than `max_runtime` is recorded
  `overran` on both engines; on Laravel its lock may already have expired.
- crunz has no event for a run skipped because the previous one is still running, so that skip is not recorded.
- crunz evaluates conditions on every server before the one-server claim, so a condition that holds a task back
  is recorded once per server.
- A failed run has no exit code or exception in its record: Laravel's failure hook passes neither, and crunz keeps
  the exit code in an internal class.
- `schedule:clear-cache` clears only Laravel's own locks. A lock of the package expires on its TTL; a
  killed run of an app task with the default `withoutOverlapping()` holds its key for 24 hours.
- A run's start time is kept in memory of the process that reports the run, so `overran` is measured on the
  engines as they run today: `schedule:run` in the foreground on Laravel, the crunz parent process.
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
