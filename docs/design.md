# Design

Why `galvani/php-scheduler-pro` is built the way it is: the assignment it answers, the decisions and their
alternatives, and what is left out on purpose. This branch is the assignment's required minimum; the `full`
branch carries the extensions. For how to use it, see the [README](../README.md); for how it is tested,
[testing.md](testing.md).

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
- **The package owns the task list.** Tasks are data (`TaskDefinition`), read from YAML and handed to the engine
  every tick through `SchedulerInterface::registerWith()`. A declarative task instead of an engine's fluent
  builder is what removes the coupling: the fluent API *is* the engine.
- **The package plugs its own mutex into the engine's existing lock slots.** Laravel resolves a bound
  `EventMutex` and `SchedulingMutex` before its cache ones; crunz's `preventOverlapping()` takes any Symfony
  Lock store. Both slots exist already, so no engine is wrapped or patched.

## The interfaces

The contracts, with every parameter and its type. Refined types (`positive-int`, `list<…>`) are PHPDoc, checked
by PHPStan at level max; every exception is checked and declared.

### Scheduler: the task list

```php
namespace Scheduling;

interface SchedulerInterface
{
    /** Every task from the source, and every row that could not be read. Never throws. */
    public function tasks(): TaskSet;

    /** Hands the task list to an engine; returns what it took and every problem. */
    public function registerWith(SchedulerEngineInterface $engine): TaskSet;
}

interface SchedulerEngineInterface
{
    /** @return non-empty-string the engine's name, e.g. `laravel`, `crunz`; also the problem source */
    public function name(): string;

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
    DateTimeZone $timezone,            // the zone the schedule is read in
    LockPolicy $lock,                  // new LockPolicy(int $maxRuntimeSeconds, float $margin = 1.5)
    bool $enabled = true,
    AdapterOptions $options = new AdapterOptions(),   // array<string, array<string, mixed>> per engine
);

new Command(
    string $name,                      // a console command name: letters, digits, : _ . -
    array $arguments = [],             // list<string>, quoted by the engine
    array $options = [],               // array<non-empty-string, string|list<string>|true>; true is a flag
);
```

Where the tasks come from:

```php
namespace Scheduling\Task;

interface TaskSourceInterface
{
    /** Never throws: an unreadable source or a bad row is a TaskProblem in the set. */
    public function load(): TaskSet;
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

### Why these shapes

| Decision | The alternative | Why, and what it costs |
| --- | --- | --- |
| A task is data (`TaskDefinition`), handed to the engine | An adapter over the engine's fluent builder (`->daily()->withoutOverlapping()`) | The fluent builder *is* the engine; mirroring it binds every caller to it. Data can live in YAML, is validated once, and means the same on every engine. Cost: only what is modelled can be scheduled; closures and queued jobs cannot. |
| The engine keeps timing and execution | Our own loop that evaluates cron and starts processes | Laravel and crunz already do this well; the assignment asks for swapping, not a third scheduler. Cost: the engines' limits stay (a one-minute tick, missed runs lost). |
| `acquire(LockKey, int $ttlSeconds): ?LockInterface` | Laravel's shape, `create(Event)` / `exists(Event)` / `forget(Event)`; or `bool acquire(string)` with `release(string)` | No framework type in the contract, and the returned lock carries the owner's token, so only the holder can release or extend it; that is exactly what Laravel's cache mutex lacks. Cost: the lock lives in the process that took it, so a run released by another process (Laravel's `runInBackground()`) cannot use it. |
| "Held" is `null`; "cannot tell" is `LockUnavailableException` | One exception for both, or `false` for both | Held is a normal answer, so it is a value. Not knowing is not, and the caller must not treat it as free: it skips the run. Running twice is worse than running late. |
| Every lock has a TTL | A lock held until released | A holder that dies frees the key on its own. Cost: the TTL must cover the run: `max_runtime × margin` on Laravel, a lock crunz extends while the task runs. |
| Redis `SET NX PX`, release and extend in Lua with a token check | The framework cache lock, database advisory locks, Redlock over several nodes | Atomic, owner-checked, outside the cache that `cache:clear` wipes, and every engine can reach it. Cost: one Redis node; see the trade-offs below. |
| Keys are built only in `LockKey` | Each bridge builds its own | Servers on different engines compute the same key for the same task and exclude each other. |
| Checked exceptions under one abstract `SchedulerException` | Unchecked exceptions | Every failure a caller must handle is in the signature, and PHPStan enforces it. Misconfiguration (a prefixed Redis connection) stays unchecked: it is a programming error, not a runtime case. |
| Reading never throws; a bad row is a `TaskProblem` | Fail the whole schedule on one bad row | One broken task never stops the others. Cost: problems must be logged and read, which both bridges do. |

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
  `scheduler`, added as a copy of the app's `default` connection without its key prefix. A connection with a key
  prefix is refused: the prefix would be added to every lock key, and servers on another engine would no longer
  share the locks.
- **Redis**: `SET key token NX PX ttl` takes the lock atomically; Lua scripts release or extend it only when
  the stored token is this holder's. A holder that dies leaves a key that expires on its own. Redis
  unreachable, or answering with an error (phpredis returns `false` for some error replies instead of throwing,
  so every command checks `getLastError()`), throws the checked `LockUnavailableException`, and the run is
  skipped: running twice is worse than running late.
- **Why Laravel's is unreliable:** its locks live in the cache, so `cache:clear` wipes them; atomicity
  depends on the cache driver; and its release has no owner check.

## Exceptions

Every exception extends the abstract, checked `SchedulerException`: `InvalidTaskException` (a task breaks a
rule) and `LockUnavailableException` (the lock storage cannot be reached or answers with an error). Every method
that can throw declares it, constructors included, and PHPStan enforces it. Reading never throws: an unreadable
source or a bad row is a `TaskProblem`, and every other task still runs.

Misconfiguration is not a runtime case and stays unchecked: a Redis connection with a key prefix throws
`InvalidArgumentException`. On Laravel only the commands that read the schedule build the scheduler, so a
misconfiguration fails `schedule:run` and never other artisan commands or a deploy.

## The application's existing Laravel schedule

An application that already schedules commands in `routes/console.php` keeps them. Two levels:

1. **Untouched:** they keep running as before.
2. **Our lock, no code change:** for an event the package did not register, our Laravel lock slots
   lock `scheduler:external:<Laravel's mutex name>` for the minutes the app gave `withoutOverlapping()`, and
   claim the same name per minute. Background tasks stay on Laravel's lock (see the README's limitations), in the
   store `cache.schedule_store` names.

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

[Symfony Scheduler in detail](https://github.com/galvani/php-scheduler-pro/blob/full/docs/design.md#symfony-scheduler-in-detail),
on the `full` branch: how the three engines run, worker or crontab, and what the bridge needs.

## Known trade-offs

What the design accepts, and what each would take to change.

- **One Redis node.** The lock is as safe as that node. A failover to a replica that had not received the
  latest `SET` can hand the same lock to a second server. Redlock over several independent nodes, or a store
  with consensus, would close it; for jobs that tolerate a rare duplicate, one node with persistence is the
  usual choice.
- **Server clocks.** The one-server claim is keyed by the minute on each server's clock. Servers more than a few
  seconds apart around a minute boundary claim different minutes and both run; the servers need NTP.
- **Daylight saving time.** Claim keys are in UTC. A task scheduled in the hour a local clock repeats runs twice,
  as cron itself does.
- **The application's own Laravel tasks change lock.** Installing the package binds our mutex into Laravel's
  `EventMutex` and `SchedulingMutex`, so the app's `withoutOverlapping()` and `onOneServer()` entries move onto
  the package's Redis, and are skipped, not run, when that Redis is down. There is no switch to keep them on
  Laravel's cache lock.
- **`schedule:clear-cache`** clears only Laravel's own locks. A lock of the package expires on its TTL; an app
  task killed mid-run with the default `withoutOverlapping()` keeps its key for 24 hours.
- **Laravel's API surface.** The bridge uses Laravel's public `Schedule` API and its two lock contracts
  (`EventMutex`, `SchedulingMutex`), and builds the artisan command line itself, because `compileParameters()`
  leaves a numeric value or one starting with `-` unquoted. The suite runs on Laravel 13; on Laravel 12 the
  install test runs a task end to end.
- **crunz `--force` and `--task`** run tasks past every filter: no minute claim and no overlap lock.

## Designed, not built

Each of these fits the interfaces without a redesign; each is beyond the assignment.

- a `dispatch` execution mode (the engine calls our own `run-task <id>` command) and an `enqueue`
  mode (the run goes to a queue);
- catching up missed runs, retrying failed ones, and a watcher that notices a run that never started;
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

## What the full branch adds

The `full` branch carries these extensions; they are built on the same interfaces.

- The run record: `RunListenerInterface`, run ids and `overran` for runs longer than their `max_runtime`.
- The database task source: a PDO repository with `schedule()`, `disable()` and `remove()` on the scheduler.
- The capability report: per engine, native, polyfilled or unavailable.
- Conditions on a task: time windows and environments.
- The Symfony bundle and a crunz-on-Symfony demo app.
- `scheduling:export`, which prints an app's existing Laravel schedule as YAML.
- The detailed Symfony Scheduler analysis: how the three engines run, worker or crontab, and the bridge.
