# Emorfiq — Scheduler and Mutex design

Hiring test from Emorfiq: design the class interfaces for a Scheduler and a Mutex so the
Laravel implementation can be swapped at any time (crunz is the named alternative) and the
system is not bound to Laravel. Laravel's own mutex is unreliable, so the mutex is our own.
The design goes down to input parameters and their types. The current implementation must
schedule and run crons/jobs at a given time; crontab notation is enough.

The answer has to be one Jan stands behind and can defend in a follow-up call: why each
interface, which alternatives existed, and what each choice costs.

The original assignment (Czech) is in `ASSIGNMENT.md`.

## The package

A framework-independent scheduler component with its own mutex, and bridges to Laravel's
scheduler and crunz. The core depends on no framework; each bridge maps the core's interfaces onto
its engine.

```
src/
├── SchedulerInterface.php
├── Scheduler.php
├── SchedulerEngineInterface.php
├── Exception/
├── Task/
├── Lock/
├── Run/
└── Bridge/
    ├── Laravel/
    └── Crunz/
```

Checks: `composer phpstan`, `composer cs-fix`, `composer phpmd`.

## The next engine: Symfony Scheduler

The package ships bridges for the two engines the assignment names, Laravel's scheduler and crunz.
We consider Symfony's Scheduler component (`symfony/scheduler` with `symfony/messenger`) the best
engine of the three, and adding it is a small, contained piece of work.

**Why it is the best option:**

- It remembers its last run (a stateful schedule), so runs missed while the servers were down are
  caught up. Laravel's scheduler and crunz forget them.
- It runs a task as a Messenger message: in the same process, or sent to a queue and handled by any
  worker on any machine.
- It locks the schedule across workers with Symfony Lock, the same mechanism this package already
  plugs its mutex into.
- It is maintained by the Symfony core team.

**Why it costs little, in Laravel too:**

- The core depends on no framework, so a new engine is one more bridge: a `SchedulerEngineInterface`
  implementation that turns each `TaskDefinition` into a `RecurringMessage` with a cron trigger.
- The crunz bridge already adapts our mutex to Symfony Lock (`Bridge/Crunz/MutexStore`); the same
  store serves Symfony Scheduler's lock.
- Its run events (before run, after run, on failure) map onto `RunListenerInterface` the way
  Laravel's and crunz's hooks do.
- Scheduler and Messenger are standalone components. In a Laravel app they need a service provider
  that builds the message bus and an artisan command that starts the worker; nothing in the core
  changes.

## Known limitations

These are understood and deliberately left out; each goes beyond the scope of the assignment.

- **No background runs in the Laravel bridge.** Laravel's `runInBackground()` finishes a task in a
  separate `schedule:finish` process, which never held the lock and so has no owner token to release
  it with. The bridge therefore runs the tasks due in one minute one after another; a long task delays
  the others due in that minute. Supporting it means releasing that lock by key instead of by owner.
- **Laravel's lock slot cannot extend a lock.** On Laravel a task keeps its lock for
  `max_runtime × margin`; a run that outlives it is recorded as `overran`. crunz extends its locks
  while a task runs, so there the lock lasts as long as the task.
- **crunz reports no "still running" skip.** crunz has no event for a skipped task, so that skip is
  not recorded in the run record on crunz.
- **Failure details on Laravel.** For a command task, Laravel's failure hook gives neither the exit
  code nor the exception, so the run record says `failed` without a reason.
- **Designed, not built:** a `dispatch` execution mode (the engine calls our own `run-task <id>`
  command), an `enqueue` mode (the run goes to a queue), catching up missed runs, retrying failed
  ones, and a watcher that notices a run that never started.
- **Not implemented yet:** the YAML and database task sources, the run record's storage, the Laravel
  service provider with its config file, and tests.
