# Design

Why `galvani/php-scheduler-pro` is built the way it is: the assignment it answers, the decisions and their
alternatives, and what is left out on purpose. For how to use it, see the [README](../README.md); for how it
is tested, [testing.md](testing.md).

## The assignment

A hiring test (the Czech original is in [`ASSIGNMENT.md`](../ASSIGNMENT.md)): design the class interfaces
for a Scheduler and a Mutex so that the Laravel implementation can be swapped at any time (crunz is the
named alternative) and the system is not bound to Laravel. Laravel's own mutex is unreliable, so the mutex
is our own. The design goes down to input parameters and their types; the current implementation must
schedule and run jobs at a given time, and crontab notation is enough. The answer has to be defensible on
a follow-up call: why each interface, which alternatives existed, and what each choice costs.

## The approach

**The engine stays the scheduler; the package replaces only what is unreliable and adds only what is
missing.**

- **The engine keeps timing and execution.** Laravel's scheduler and crunz already decide what is due and
  run it well. Rebuilding that would be the "replacement" nobody asked for.
- **The package owns the task list.** Tasks are data (`TaskDefinition`), read from YAML and optionally a
  database table, and handed to the engine every tick through `SchedulerInterface::registerWith()`. A
  declarative task instead of an engine's fluent builder is what removes the coupling: the fluent API *is*
  the engine.
- **The package plugs its own mutex into the engine's existing lock slots.** Laravel resolves a bound
  `EventMutex` and `SchedulingMutex` before its cache ones; crunz's `preventOverlapping()` takes any Symfony
  Lock store. Both slots exist already, so no engine is wrapped or patched.
- **The package listens to the engine's hooks** and turns them into one run stream
  (`RunListenerInterface`) and one run record.

## The interfaces

The contracts, with every parameter and its type. Refined types (`positive-int`, `list<…>`) are PHPDoc, checked
by PHPStan at level max; every exception is checked and declared.

### Scheduler: the task list

```php
namespace Scheduling;

interface SchedulerInterface
{
    /** Every task from every source, and every row that could not be read. Never throws. */
    public function tasks(): TaskSet;

    /** Adds a task; never replaces one. @throws TaskConflictException|TaskNotWritableException|StorageException */
    public function schedule(TaskDefinition $task): void;

    /** Keeps the task but stops it running. @throws TaskNotFoundException|TaskNotWritableException|StorageException */
    public function disable(TaskId $id): void;

    /** Deletes the task. @throws TaskNotFoundException|TaskNotWritableException|StorageException */
    public function remove(TaskId $id): void;

    /** Hands the task list to an engine; returns what it took and every problem. */
    public function registerWith(SchedulerEngineInterface $engine): TaskSet;
}

interface SchedulerEngineInterface
{
    /** @return non-empty-string the engine's name, e.g. `laravel`, `crunz`; also the problem source */
    public function name(): string;

    /** Per capability: Native, Polyfilled or Unavailable. */
    public function capabilities(): CapabilityReport;

    /**
     * @param array<string, TaskDefinition> $tasks keyed by task id
     * @return list<TaskProblem> the tasks the engine refused; the rest are registered
     */
    public function register(array $tasks): array;
}
```

A task is an immutable value, validated when it is built:

```php
new TaskDefinition(
    TaskId $id,                        // lowercase letters, digits, . _ -; at most 100
    CronExpression $schedule,          // a crontab expression
    Command $command,                  // see below
    DateTimeZone $timezone,            // the zone the schedule and the conditions are read in
    LockPolicy $lock,                  // new LockPolicy(int $maxRuntimeSeconds, float $margin = 1.5)
    bool $enabled = true,
    AdapterOptions $options = new AdapterOptions(),   // array<string, array<string, mixed>> per engine
    array $conditions = [],            // list<ConditionInterface>, all must hold
);

new Command(
    string $name,                      // a console command name: letters, digits, : _ . -
    array $arguments = [],             // list<string>, quoted by the engine
    array $options = [],               // array<non-empty-string, string|list<string>|true>; true is a flag
);
```

Where the tasks come from, and the one source that can be written:

```php
namespace Scheduling\Task;

interface TaskSourceInterface
{
    /** Never throws: an unreadable source or a bad row is a TaskProblem in the set. */
    public function load(): TaskSet;
}

interface TaskRepositoryInterface extends TaskSourceInterface
{
    /** @throws TaskConflictException|StorageException */
    public function add(TaskDefinition $task): void;
    /** @throws TaskNotFoundException|StorageException */
    public function disable(TaskId $id): void;
    /** @throws TaskNotFoundException|StorageException */
    public function remove(TaskId $id): void;
}
```

```php
namespace Scheduling\Task\Condition;

interface ConditionInterface
{
    /** $now is in the task's timezone. */
    public function isSatisfied(DateTimeImmutable $now): bool;
}
```

### Mutex: the lock

```php
namespace Scheduling\Lock;

interface MutexInterface
{
    /**
     * @param positive-int $ttlSeconds the key frees itself after this, even if the holder dies
     * @return LockInterface|null null when another holder has the key
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error
     */
    public function acquire(LockKey $key, int $ttlSeconds): ?LockInterface;

    /** @throws LockUnavailableException */
    public function isLocked(LockKey $key): bool;
}

interface LockInterface
{
    public function expiresAt(): DateTimeImmutable;

    /**
     * @param positive-int $ttlSeconds the new lifetime, counted from now
     * @return bool false when the lock was already lost
     * @throws LockUnavailableException
     */
    public function extend(int $ttlSeconds): bool;

    /** Releases only if this holder still owns the key. @throws LockUnavailableException */
    public function release(): void;
}
```

`LockKey` has a private constructor and four named ones (`forTask`, `forTaskAt`, `forExternal`,
`forExternalAt`), so a key cannot be built anywhere else.

### The run record

```php
namespace Scheduling\Run;

interface RunListenerInterface
{
    /** The run began, holding its locks; the returned id names this run until it ends. */
    public function started(TaskId $id, DateTimeImmutable $at): RunId;

    public function finished(TaskId $id, RunId $run, DateTimeImmutable $at): void;

    /**
     * @param int|null       $exitCode when the engine reports it
     * @param Throwable|null $error    when the engine reports it
     */
    public function failed(TaskId $id, RunId $run, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void;

    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void;
}

interface RunRepositoryInterface
{
    /** @throws StorageException */
    public function append(RunRecord $record): void;

    /** The newest record of a task in a state: the read side, for monitoring. @throws StorageException */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord;
}
```

### Why these shapes

| Decision | The alternative | Why, and what it costs |
| --- | --- | --- |
| A task is data (`TaskDefinition`), handed to the engine | An adapter over the engine's fluent builder (`->daily()->withoutOverlapping()`) | The fluent builder *is* the engine; mirroring it binds every caller to it. Data can live in YAML or a table, is validated once, and means the same on every engine. Cost: only what is modelled can be scheduled; closures and queued jobs cannot. |
| The engine keeps timing and execution | Our own loop that evaluates cron and starts processes | Laravel and crunz already do this well; the assignment asks for swapping, not a third scheduler. Cost: the engines' limits stay (a one-minute tick, missed runs lost). |
| `acquire(LockKey, int $ttlSeconds): ?LockInterface` | Laravel's shape, `create(Event)` / `exists(Event)` / `forget(Event)`; or `bool acquire(string)` with `release(string)` | No framework type in the contract, and the returned lock carries the owner's token, so only the holder can release or extend it; that is exactly what Laravel's cache mutex lacks. Cost: the lock lives in the process that took it, so a run released by another process (Laravel's `runInBackground()`) cannot use it. |
| "Held" is `null`; "cannot tell" is `LockUnavailableException` | One exception for both, or `false` for both | Held is a normal answer, so it is a value. Not knowing is not, and the caller must not treat it as free: it skips the run. Running twice is worse than running late. |
| Every lock has a TTL | A lock held until released | A holder that dies frees the key on its own. Cost: the TTL must cover the run: `max_runtime × margin` on Laravel, a lock crunz extends while the task runs. A run longer than `max_runtime` is recorded `overran`. |
| Redis `SET NX PX`, release and extend in Lua with a token check | The framework cache lock, database advisory locks, Redlock over several nodes | Atomic, owner-checked, outside the cache that `cache:clear` wipes, and every engine can reach it. Cost: one Redis node; see the trade-offs below. |
| Keys are built only in `LockKey` | Each bridge builds its own | Servers on different engines compute the same key for the same task and exclude each other. |
| Engines report capabilities: native, polyfilled, unavailable | A lowest-common-denominator wrapper | One full surface on every engine, and an engine that cannot do something says so instead of ignoring it. Cost: the refusal is enforced only for conditions today; the other capabilities are reported. |
| Checked exceptions under one abstract `SchedulerException` | Unchecked exceptions | Every failure a caller must handle is in the signature, and PHPStan enforces it. Misconfiguration (a prefixed Redis connection, a bad table name) stays unchecked: it is a programming error, not a runtime case. |
| Reading never throws; a bad row is a `TaskProblem` | Fail the whole schedule on one bad row | One broken task never stops the others. Cost: problems must be logged and read, which both bridges do. |
| `schedule()` only adds; the database decides a conflict | An upsert | Two writers adding the same id get one success and one conflict; nobody silently overwrites a task. Changing a task is remove and schedule. |
| Engines report into a stream (`RunListenerInterface`), stored by a repository | Engines write records themselves | One translation per engine, one recording implementation; fanning the stream out to monitoring needs no engine change. |
| `started()` returns a `RunId` that the end carries | Match an end to the newest recorded start | A start and its end are paired in the history, and `overran` is measured from this run's own start even when another copy started meanwhile. |
| One `SchedulerInterface` for reading, writing and handing tasks to an engine | Separate reader, writer and engine-binding interfaces | One implementation and two bridges call it; splitting it would add interfaces nothing needs today. Cost: application code that injects it can also call `registerWith()`. |

## The mutex

- **One generic lock.** `MutexInterface::acquire(LockKey $key, int $ttlSeconds): ?LockInterface` — the caller
  picks the key and the lifetime. Laravel's two slots are the same primitive with different keys: the
  overlap lock per task (`scheduler:task:<id>`) and the one-server claim per task and minute
  (`scheduler:task:<id>:<UTC YmdHi>`, an hour, never released).
- **The overlap lock's lifetime follows the engine.** Laravel cannot extend a lock while a task runs, so there it
  lives `max_runtime × margin`. crunz takes it for two minutes and extends it from its run loop while the task
  runs, so there it lasts exactly as long as the run, and `margin` plays no part. A Redis error while crunz
  extends it keeps the lock as held and retries on crunz's next pass, instead of stopping crunz and every task it
  runs.
- **Keys are built in one place** (`LockKey`). Servers on different engines compute the same key for the
  same task and exclude each other; lock safety rests on the key scheme, not on which engine is enabled.
- **The application's own Redis connection.** On Laravel the scheduler uses a Laravel-managed connection,
  `scheduler`, added as a copy of the app's `default` connection without its key prefix; on Symfony it takes a
  DSN (`%env(REDIS_URL)%`) or an existing `Redis` service. A connection with a key prefix is refused: the prefix
  would be added to every lock key, and servers on another engine would no longer share the locks.
- **Redis**: `SET key token NX PX ttl` takes the lock atomically; Lua scripts release or extend it only when
  the stored token is this holder's. A holder that dies leaves a key that expires on its own. Redis
  unreachable, or answering with an error (phpredis returns `false` for some error replies instead of throwing,
  so every command checks `getLastError()`), throws the checked `LockUnavailableException`, and the run is
  skipped: running twice is worse than running late.
- **Why Laravel's is unreliable:** its locks live in the cache, so `cache:clear` wipes them; atomicity
  depends on the cache driver; and its release has no owner check.
- **A run longer than its `max_runtime`** is recorded `overran`, not `finished` or `failed`: its lock may have
  expired while it ran, so a retry must never start another copy beside it. It is measured from the run's own
  start, carried by its `RunId`, so a second copy that started meanwhile does not hide it.

## Exceptions

Every exception extends the abstract, checked `SchedulerException`: `InvalidTaskException` (a task breaks a
rule), `TaskConflictException` (an id is already scheduled; adding never replaces), `TaskNotFoundException`
(disabling or removing an id that is not scheduled), `TaskNotWritableException`, `LockUnavailableException`,
`StorageException`, `MissingPackageException`, `InvalidRunIdException` (a stored run id that is not one).
Every method that can throw declares it, constructors included, and PHPStan enforces it. Reading never throws: an unreadable source or a bad row is a `TaskProblem`, and every
other task still runs; an unreachable database leaves the YAML tasks running on both frameworks.

Misconfiguration is not a runtime case and stays unchecked: a Redis connection with a key prefix, an invalid
Redis DSN or table name throws `InvalidArgumentException`. On Laravel only the commands that read the schedule
build the scheduler, so a misconfiguration fails `schedule:run` and never `migrate` or a deploy.

## An augmenting proxy: native, polyfilled, unavailable

The package is not a lowest-common-denominator wrapper. It exposes one full surface and fulfils
each capability the best way the active engine allows: the engine does it (**native**), the package
does it on top (**polyfilled**), or neither can (**unavailable**). Every engine reports this through
`SchedulerEngineInterface::capabilities()`, and `Scheduler` refuses a task that needs an unavailable
capability instead of letting it silently lose behaviour. Today the only capability a task can need is
conditions; the rest of the report describes the engine, and a capability a task can ask for (sub-minute, say)
gets its check the day `TaskDefinition` can express it.

| Capability | Laravel | crunz |
| --- | --- | --- |
| Overlap lock | native (our Redis mutex in Laravel's lock slot) | native (our Redis mutex as crunz's lock store) |
| One server only | native (our mutex in Laravel's slot) | **polyfilled**: crunz has none; the package claims each minute |
| Conditions | native (`when()`) | native (`when()`) |
| Run record | **polyfilled** | **polyfilled** |
| Sub-minute | unavailable | unavailable |
| Missed-run catch-up | unavailable | unavailable |

The two unavailable ones are within the design: Laravel's `everySecond()` and a Symfony Scheduler
bridge would make sub-minute native; a catch-up add-on (designed, not built: see below) would polyfill
catch-up on any engine.

## The application's existing Laravel schedule

An application that already schedules commands in `routes/console.php` keeps them. Three levels:

1. **Untouched:** they keep running as before.
2. **Our lock, no code change (built):** for an event the package did not register, our Laravel lock slots
   lock `scheduler:external:<Laravel's mutex name>` for the minutes the app gave `withoutOverlapping()`, and
   claim the same name per minute. Background tasks stay on Laravel's lock (see the README's limitations), in the
   store `cache.schedule_store` names.
3. **Moved into the package (built):** `php artisan scheduling:export` prints the entries as YAML for the
   tasks file and lists what cannot be expressed as data. A pasted task and its PHP line change in the same
   deploy, or the task runs twice under two different locks.

## The next engine: Symfony Scheduler

**In short:** of the three engines we looked at, Symfony's Scheduler component is the best, and adding it is one
more bridge, in a Laravel app too.

- **A worker boots once** and serves every run from memory, like FrankenPHP's worker mode, instead of booting
  the framework every minute and again per task. It can still run from crontab when that is preferred.
- **It scales out:** with Messenger, runs go to a transport and any number of workers handle them. crunz
  runs everything on the machine that ticked.
- **It has what our two engines lack:** runs every second, and catch-up of runs missed while the servers were
  down.
- **It costs little:** one `SchedulerEngineInterface` implementation; our mutex already speaks Symfony Lock;
  in a Laravel 13 app with this package it adds seven packages.

[Symfony Scheduler in detail](#symfony-scheduler-in-detail): how the three engines run, worker or crontab,
and what the bridge needs.

## Known trade-offs

What the design accepts, and what each would take to change.

- **One Redis node.** The lock is as safe as that node. A failover to a replica that had not received the
  latest `SET` can hand the same lock to a second server. Redlock over several independent nodes, or a store
  with consensus, would close it; for jobs that tolerate a rare duplicate, one node with persistence is the
  usual choice.
- **Server clocks.** The one-server claim is keyed by the minute on each server's clock. Servers more than a few
  seconds apart around a minute boundary claim different minutes and both run; the servers need NTP.
- **Daylight saving time.** Claim keys are in UTC. A task scheduled in the hour a local clock repeats runs twice,
  as cron itself does; a `between` window inside the hour a clock skips never matches.
- **The application's own Laravel tasks change lock.** Installing the package binds our mutex into Laravel's
  `EventMutex` and `SchedulingMutex`, so the app's `withoutOverlapping()` and `onOneServer()` entries move onto
  the package's Redis, and are skipped, not run, when that Redis is down. There is no switch to keep them on
  Laravel's cache lock.
- **`schedule:clear-cache`** clears only Laravel's own locks. A lock of the package expires on its TTL; an app
  task killed mid-run with the default `withoutOverlapping()` keeps its key for 24 hours.
- **Laravel internals.** `scheduling:export` reads a few protected properties of Laravel's scheduled events by
  reflection; the task bridge builds the command line itself instead of relying on Laravel's parameter
  compilation. Both are pinned by tests on Laravel 13; on Laravel 12 the install test runs a task end to end,
  but `scheduling:export` is not run there.
- **crunz `--force` and `--task`** run tasks past every filter: no minute claim, no overlap lock, no
  condition.
- **Run history keys** (`scheduler:runs:<id>`) stay after a task is removed; they are capped per task, not
  expired.

## Designed, not built

Each of these fits the interfaces without a redesign; each is beyond the assignment.

- a `dispatch` execution mode (the engine calls our own `run-task <id>` command) and an `enqueue`
  mode (the run goes to a queue);
- catching up missed runs, retrying failed ones, and a watcher that notices a run that never started;
- fanning the run stream out to several listeners, or onto a PSR-14 event dispatcher as event
  objects (`TaskStarted`, `TaskFailed`, …);
- two engines side by side during a migration, each task naming its engine, and a lock that also
  claims Laravel's own cache key so an app not yet running the package is excluded too;
- the package as an HTTP service: the same `SchedulerInterface` over HTTP, the engine a setting of
  the service; the mutex stays a library;
- **Symfony's Lock component as the mutex in a Symfony app.** A `SymfonyLockMutex implements MutexInterface`
  wrapping the application's `LockFactory` (`framework.lock`) would make the lock whatever store the app already
  configured: Redis, PostgreSQL advisory locks and others. `MutexInterface` stays the same; only the implementation
  behind it becomes the framework's own. Servers on other engines share the lock only if they use the same store
  and keys.

## Deliberately not done

- **A release by key on `MutexInterface`.** It would let background runs (Laravel's `runInBackground()`,
  released from a separate `schedule:finish` process) use our lock, but it brings back Laravel's race: a run
  that outlives its lock lets the next run take it, and the first run's finish then deletes the second
  run's lock. Our lock is released only by its owner; background tasks stay on Laravel's lock.
- **A full Symfony bundle.** `SchedulingBundle` wires the package into a Symfony app and no more. The bundle we
  would ship as a product (a Flex recipe, a configuration reference, autoconfiguration, console commands, tests
  across Symfony versions) is beyond the scope of the assignment, which names Laravel and crunz.
- **A Symfony Flex recipe.** It would register the bundle and copy its config on `composer require`, but
  Flex finds recipes only in the public `recipes-contrib` (a public package) or at an endpoint every app
  must name. For a private package that costs more than the two files it saves.

## Symfony Scheduler in detail

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
  runs schedules against a map of handlers. In a Laravel 13 app with this package, `composer require
  symfony/scheduler symfony/lock symfony/cache --dry-run` installs seven packages (scheduler, lock, cache and
  their dependencies; Laravel already ships event-dispatcher and clock); Messenger is one package more.
- In a Laravel app it needs a few lines in the service provider and an artisan command that starts the
  worker. The crontab line `schedule:run` is replaced by a supervised `scheduling:work`; no task and no
  application class changes.
