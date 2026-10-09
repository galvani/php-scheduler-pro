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

## The mutex

- **One generic lock.** `MutexInterface::acquire(LockKey $key, int $ttlSeconds): ?LockInterface` — the caller
  picks the key and the lifetime. Laravel's two slots are the same primitive with different keys: the
  overlap lock per task (`scheduler:task:<id>`, lifetime `max_runtime × margin`) and the one-server claim per
  task and minute (`scheduler:task:<id>:<UTC YmdHi>`, an hour, never released).
- **Keys are built in one place** (`LockKey`). Servers on different engines compute the same key for the
  same task and exclude each other; lock safety rests on the key scheme, not on which engine is enabled.
- **Redis**: `SET key token NX PX ttl` takes the lock atomically; Lua scripts release or extend it only when
  the stored token is this holder's. A holder that dies leaves a key that expires on its own. Redis
  unreachable throws the checked `LockUnavailableException`, and the run is skipped: running twice is
  worse than running late. The connection is the scheduler's own, without the application's key prefix,
  so every engine sees the same keys.
- **Why Laravel's is unreliable:** its locks live in the cache, so `cache:clear` wipes them; atomicity
  depends on the cache driver; and its release has no owner check.
- **A run longer than its `max_runtime`** is recorded `overran`, not `failed`: its lock may have expired
  while it ran, so a retry must never start another copy beside it.

## Exceptions

Every exception extends the abstract, checked `SchedulerException`: `InvalidTaskException` (a task breaks a
rule), `TaskNotWritableException`, `LockUnavailableException`, `StorageException`. Every method that can
throw declares it, constructors included, and PHPStan enforces it. Reading never throws: an unreadable
source or a bad row is a `TaskProblem`, and every other task still runs.

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

## The application's existing Laravel schedule

An application that already schedules commands in `routes/console.php` keeps them. Three levels:

1. **Untouched:** they keep running as before.
2. **Our lock, no code change (built):** for an event the package did not register, our Laravel lock slots
   lock `scheduler:external:<Laravel's mutex name>` for the minutes the app gave `withoutOverlapping()`, and
   claim the same name per minute. Background tasks stay on Laravel's lock (see the README's limitations).
3. **Moved into the package (built):** `php artisan scheduling:export` prints the entries as YAML for the
   tasks file and lists what cannot be expressed as data. A pasted task and its PHP line change in the same
   deploy, or the task runs twice under two different locks.

## The next engine: Symfony Scheduler

**TL;DR:** of the three engines we looked at, Symfony's Scheduler component is the best, and adding it is one
more bridge, in a Laravel app too.

- **A worker boots once** and serves every run from memory, like FrankenPHP's worker mode, instead of booting
  the framework every minute and again per task. It can still run from crontab when that is preferred.
- **It scales out:** with Messenger, runs go to a transport and any number of workers handle them. crunz
  runs everything on the machine that ticked.
- **It has what our two engines lack:** runs every second, and catch-up of runs missed while the servers were
  down.
- **It costs little:** one `SchedulerEngineInterface` implementation; our mutex already speaks Symfony Lock;
  on top of Laravel 13 it adds 11 packages and 2 MB.

[Symfony Scheduler in detail](#symfony-scheduler-in-detail): how the three engines run, worker or crontab,
and what the bridge needs.

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
  the service; the mutex stays a library.

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
  runs schedules against a map of handlers. On top of Laravel 13 it adds 11 packages and 2 MB
  (scheduler, lock, cache, event-dispatcher and their contracts); Messenger is one package more.
- In a Laravel app it needs a few lines in the service provider and an artisan command that starts the
  worker. The crontab line `schedule:run` is replaced by a supervised `scheduling:work`; no task and no
  application class changes.
