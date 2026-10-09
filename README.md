# Emorfiq — Scheduler and Mutex design

Hiring test from Emorfiq: design the class interfaces for a Scheduler and a Mutex so the
Laravel implementation can be swapped at any time (crunz is the named alternative) and the
system is not bound to Laravel. Laravel's own mutex is unreliable, so the mutex is our own.
The design goes down to input parameters and their types. The current implementation must
schedule and run crons/jobs at a given time; crontab notation is enough.

The answer has to be one Jan stands behind and can defend in a follow-up call: why each
interface, which alternatives existed, and what each choice costs.

The original assignment (Czech) is in `ASSIGNMENT.md`.

> **On comments:** the code is commented more than production code would be, on purpose, for
> readability. It is a demo meant to be read and presented, so every class, interface and public
> method says what it is and why it exists.

## The package

A framework-independent scheduler component with its own mutex, run conditions, a run record, and
bridges to Laravel's scheduler and crunz. The core depends on no framework; each bridge maps the
core's interfaces onto its engine. The engine keeps deciding when a task is due and running it; the
package owns the task list, plugs its own Redis mutex into the engine's lock slots, and listens to
the engine's hooks.

```
src/
├── SchedulerInterface.php        the application's entry point; Scheduler implements it
├── SchedulerEngineInterface.php  what a bridge implements
├── Capability/                   native / polyfilled / unavailable, per engine
├── Exception/                    SchedulerException and its checked subclasses
├── Task/                         the task model, YAML and database sources
├── Lock/                         MutexInterface, LockKey, the Redis mutex
├── Run/                          the run stream (RunListenerInterface) and the run record
└── Bridge/
    ├── Laravel/                  engine, both lock slots, service provider, scheduling:export
    ├── Crunz/                    engine, Symfony Lock store over our mutex
    └── Symfony/                  bundle wiring the core onto crunz
config/scheduling.php             Laravel config, publishable
database/migrations/              the scheduler_tasks table for Laravel, publishable
demo/symfony/                     a Symfony app on crunz; prove.sh shows two servers, one run
tests/                            PHPUnit; Redis tests need a real Redis and fail without one
```

Checks: `composer cs-check`, `composer phpstan` (level max over `src` and `tests`, with checked
exceptions), `composer test` (Redis at `REDIS_HOST`/`REDIS_PORT`, default `127.0.0.1:16379`),
`composer phpmd` (advisory). The pre-commit hook runs the first two.

## Install and use

### Tasks

Tasks live in a YAML file, and optionally in a database table so recurring tasks can be added at
runtime through `SchedulerInterface::schedule()`. One row format serves both:

```yaml
tasks:
  reports-send:                    # task id: lowercase letters, digits, . _ -
    schedule: "0 6 * * *"          # crontab expression
    command: reports:send          # the console command
    arguments: [weekly]
    options: {queue: high, force: true}   # true is a flag
    timezone: Europe/Prague        # default: the app's timezone
    max_runtime: 600               # seconds; the lock lasts max_runtime × lock_margin
    lock_margin: 1.5
    enabled: true
    between: ["22:00", "06:00"]    # run only inside this window
    environments: [production]     # run only in these environments
    adapters: {laravel: {output_file: /var/log/reports.log}}
```

A bad row is reported and skipped; every other task still runs. A task id defined in YAML cannot be
changed through the database.

### Laravel 12 and 13

```bash
composer require galvani/php-scheduler-pro
php artisan vendor:publish --tag=scheduling-config        # config/scheduling.php
```

The service provider is discovered automatically. Put the tasks in `scheduling.yaml` at the project
root (or set `SCHEDULING_TASKS_FILE`) and keep Laravel's usual crontab line,
`* * * * * php artisan schedule:run`. The scheduler's Redis connection is `scheduling.redis`
(`SCHEDULING_REDIS_CONNECTION`, phpredis client required). For tasks in the database, publish and run
the migration (`--tag=scheduling-migrations`) and set `SCHEDULING_DATABASE=true`.

### The app's existing Laravel schedule

An app that already has `Schedule::command(...)` entries keeps them, and they keep running as before.
They also get our Redis mutex, under Laravel's own lock name (`scheduler:external:<mutex name>`), for
the overlap lock and the one-server claim, so the unreliable cache lock is gone for the whole
schedule without a change to the app's code. Those tasks have no run record; moving one into the
package fully is what `php artisan scheduling:export` is for: it prints the app's schedule as YAML,
ready for the tasks file, and lists the entries that cannot be expressed as data.

### Symfony (on crunz)

Register `Scheduling\Bridge\Symfony\SchedulingBundle`, configure it, and point crunz at a tasks file
that returns the bundle's schedule. `demo/symfony` is a complete, working example:

```yaml
# config/packages/scheduling.yaml
scheduling:
    tasks_file: '%kernel.project_dir%/config/scheduling.yaml'
    console_binary: 'php %kernel.project_dir%/bin/console'
    redis: {host: 127.0.0.1, port: 6379}
```

```php
// tasks/SchedulingTasks.php, found by crunz (crunz.yml: source: tasks, suffix: Tasks.php)
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'prod', false);
$kernel->boot();

return $kernel->getContainer()->get(Scheduling\Bridge\Symfony\CrunzTasks::class)->schedule();
```

Crontab: `* * * * * vendor/bin/crunz schedule:run`.

### The database table elsewhere

```sql
CREATE TABLE scheduler_tasks (
    id VARCHAR(100) PRIMARY KEY,
    definition TEXT NOT NULL   -- one task row as JSON, the same shape as a YAML entry
);
```

## An augmenting proxy: native, polyfilled, unavailable

The package is not a lowest-common-denominator wrapper. It exposes one full surface and fulfils
each capability the best way the active engine allows: the engine does it (**native**), the package
does it on top (**polyfilled**), or neither can (**unavailable**). Every engine reports this through
`SchedulerEngineInterface::capabilities()`, and `Scheduler` refuses a task that needs an unavailable
capability instead of letting it silently lose behaviour.

| Capability | Laravel | crunz |
| --- | --- | --- |
| Overlap lock | native (our Redis mutex in Laravel's lock slot) | native (our Redis mutex as crunz's lock store) |
| One server only | native (our mutex in Laravel's slot) | **polyfilled**: crunz has none; the package claims each minute |
| Conditions | native (`when()`) | native (`when()`) |
| Run record | **polyfilled** | **polyfilled** |
| Sub-minute | unavailable | unavailable |
| Missed-run catch-up | unavailable | unavailable |

The two unavailable ones are within the design: Laravel's `everySecond()` and a Symfony Scheduler
bridge would make sub-minute native; the drafted recovery add-on would polyfill catch-up on any engine.

## The next engine: Symfony Scheduler

The package ships bridges for the two engines the assignment names, Laravel's scheduler and crunz.
Of the three engines we looked at, we consider Symfony's Scheduler component (`symfony/scheduler`,
optionally with `symfony/messenger`) the best, and adding it is one more bridge.

### How the engines run

| | Laravel scheduler | crunz | Symfony Scheduler |
| --- | --- | --- | --- |
| Started by | crontab, every minute | crontab, every minute | a long-running worker, or crontab every minute |
| Bootstrap | the whole framework every minute, plus a new PHP process per task | crunz every minute, plus a new PHP process per task | once per worker; every minute when run from crontab |
| Finest interval | every second (`everySecond()` keeps the minute's process alive) | one minute | one second, without a crontab |
| Runs missed while servers were down | lost | lost | caught up (a stateful schedule stores its last run) |
| Spreading work over machines | a queued job (`$schedule->job()`) handled by queue workers | none: every due task runs on the machine that ticked | a Messenger message sent to a transport (Redis, AMQP, database) and handled by any number of workers |
| One server across machines | a cache lock | none (this package adds it) | Symfony Lock on the schedule |

### Why it is the best option

- **A worker boots once.** Laravel's scheduler and crunz start a fresh PHP process every minute,
  boot the framework, decide what is due and exit; each command then boots it again in its own
  process. Symfony's worker boots once and handles every run after that from memory, the way
  FrankenPHP's worker mode serves requests without bootstrapping each one. A task that runs every
  few seconds costs a function call, not a framework boot.
- **It scales out.** With Messenger, the scheduler only decides what is due and sends a message; a
  pool of workers on any number of machines handles the messages, with retries and a failure
  transport. Adding capacity means starting more workers. crunz cannot do this: every due task runs
  as a subprocess on the machine whose crontab fired, so more servers add redundancy (with this
  package's one-server claim) but not capacity. Laravel can, but only for tasks written as queued jobs.
- **Runs more often than once a minute,** down to every second, with no crontab at all.
- **It remembers its last run,** so runs missed while the servers were down are caught up. Laravel's
  scheduler and crunz forget them.
- **It is maintained by the Symfony core team,** and it locks the schedule with Symfony Lock, the
  same mechanism this package already plugs its mutex into.

### Worker or crontab: the choice is per deployment

The worker is not forced on anyone. Started from crontab every minute with a time limit
(`messenger:consume scheduler_default --time-limit=59`), Symfony Scheduler runs like the other two
engines: no supervisor, no long-lived state. It still keeps what the others lack: runs more often
than once a minute within that minute, catch-up of missed runs, and scaling out through Messenger.

Run as a long-lived worker instead, it boots once and serves every run from memory. That speed has the
price FrankenPHP's worker mode has: memory leaks and state left in services carry over between runs,
so the worker runs under a supervisor (systemd, Supervisor), is restarted on every deploy, and stops
itself after a memory or time limit. A task that must start clean still runs as a subprocess
(`RunProcessMessage`). The cost exists only for those who want the speed.

### Why it costs little, in Laravel too

- The core depends on no framework, so a new engine is one more bridge: a `SchedulerEngineInterface`
  implementation that turns each `TaskDefinition` into a `RecurringMessage` with a cron trigger.
- The crunz bridge already adapts our mutex to Symfony Lock (`Bridge/Crunz/MutexStore`); the same
  store serves Symfony Scheduler's lock. Its lock covers the whole schedule, so the overlap lock per
  task is taken in `PreRunEvent` and released in `PostRunEvent` / `FailureEvent`, with the same keys.
- Its run events (`PreRunEvent`, `PostRunEvent`, `FailureEvent`) map onto `RunListenerInterface` the
  way Laravel's and crunz's hooks do; `FailureEvent` carries the exception, so failures get a reason.
- Scheduler is a standalone component and does not need Messenger: `Symfony\Component\Scheduler\Scheduler`
  runs schedules against a map of handlers. On top of Laravel 13 it adds 11 packages and 2 MB
  (scheduler, lock, cache, event-dispatcher and their contracts); Messenger is one package more.
- In a Laravel app it needs a few lines in the service provider and an artisan command that starts the
  worker. The crontab line `schedule:run` is replaced by a supervised `scheduling:work`; no task and no
  application class changes.

## Known limitations

These are understood and deliberately left out; each goes beyond the scope of the assignment.

- **No background runs in the Laravel bridge, and the app's own background tasks stay on Laravel's
  lock. Intentional.** Laravel's `runInBackground()` finishes a task in a separate `schedule:finish`
  process, which never held the lock and so has no owner token to release it with; Laravel releases it
  there by key alone. Our mutex releases a lock only for the holder that took it, and that rule is the
  point of having our own mutex. So the bridge runs the package's tasks due in one minute one after
  another (a long task delays the others due that minute), and an app task with `runInBackground()`
  keeps Laravel's cache lock. The alternative is a release by key on `MutexInterface`
  (`forceRelease(LockKey)`). It would cover background runs, but it brings back Laravel's own race: a
  run that outlives its lock lets the next run take it, and the first run's `schedule:finish` then
  deletes the second run's lock, so a third copy can start beside it.
- **Laravel's lock slot cannot extend a lock.** On Laravel a task keeps its lock for
  `max_runtime × margin`; a run that outlives it is recorded as `overran`. crunz extends its locks
  while a task runs, so there the lock lasts as long as the task.
- **crunz reports no "still running" skip.** crunz has no event for a skipped task, so that skip is
  not recorded in the run record on crunz.
- **Failure details on Laravel.** For a command task, Laravel's failure hook gives neither the exit
  code nor the exception, so the run record says `failed` without a reason.
- **The app's own Laravel tasks have no run record.** They get our mutex, but they have no task id;
  `scheduling:export` moves them into the package, where they do.
- **Conditions in data are the built-in ones.** `TimeWindow` and `Environments` can be written in YAML
  or stored in the database; a custom `ConditionInterface` is code and cannot be.
- **An unreachable database task source** is logged, and the YAML tasks still run; writes through
  `SchedulerInterface` then fail.
- **What does not port from the engines:**
  - fluent frequencies (`daily()`, `weekdays()`): shorthand for cron expressions, which the package
    takes directly, so nothing is lost;
  - sub-minute runs: only Laravel has them, crunz cannot, so they cannot be common;
  - maintenance mode: a Laravel concept; it could become a condition;
  - closures and raw shell commands as tasks: they cannot live in YAML or a database. A task is a
    console command with arguments and options; shell commands could become a second command type.
- **Designed, not built:**
  - a `dispatch` execution mode (the engine calls our own `run-task <id>` command) and an `enqueue`
    mode (the run goes to a queue);
  - catching up missed runs, retrying failed ones, and a watcher that notices a run that never started;
  - fanning the run stream out to several listeners, or onto a PSR-14 event dispatcher as event
    objects (`TaskStarted`, `TaskFailed`, …);
  - two engines side by side during a migration, each task naming its engine, and a lock that also
    claims Laravel's own cache key so an app not yet running the package is excluded too;
  - the package as an HTTP service: the same `SchedulerInterface` over HTTP, the engine a setting of
    the service; the mutex stays a library.
