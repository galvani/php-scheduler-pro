<?php declare(strict_types=1);

namespace Scheduling\Capability;

/**
 * What a scheduler engine can be asked to do for a task, beyond running it on its cron expression.
 *
 * The package offers one full surface on every engine (an augmenting proxy). Each engine reports, per
 * capability, whether it does it itself, whether this package adds it on top of the engine's hooks,
 * or whether neither can do it: see Fulfilment and SchedulerEngineInterface::capabilities().
 */
enum Capability: string
{
    /**
     * A task never starts while its previous run still holds the lock `scheduler:task:<id>`.
     * Our mutex is plugged into the engine's own lock slot: Laravel's EventMutex, where the lock lives
     * max_runtime × margin seconds, and crunz's preventOverlapping store, where it lives two minutes and crunz
     * extends it while the task runs.
     */
    case OverlapLock = 'overlap_lock';

    /**
     * With several servers running the same schedule, each due minute of a task runs on one server
     * only: the first to claim `scheduler:task:<id>:<UTC minute>` runs it, the others skip it.
     * Laravel has a slot for this (SchedulingMutex); crunz has none, so the package claims the minute in skip().
     */
    case OneServer = 'one_server';

    /**
     * Run conditions on the task (TimeWindow, Environments) are evaluated at run time through the
     * engine's own when(); a task whose condition fails is skipped and recorded.
     */
    case Conditions = 'conditions';

    /**
     * Every start, finish, failure and skip is recorded with the host and a reason (RunState, SkipReason).
     * Engines only offer hooks; the package turns them into records, so this is never native.
     */
    case RunRecord = 'run_record';

    /** A task runs more often than once a minute. Both engines in scope tick once a minute. */
    case SubMinute = 'sub_minute';

    /** A run missed while every server was down is run once they are back. Neither engine remembers past ticks. */
    case MissedRunCatchUp = 'missed_run_catch_up';
}
