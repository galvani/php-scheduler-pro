<?php declare(strict_types=1);

namespace Scheduling\Run;

/** Why a run that was due did not start. */
enum SkipReason: string
{
    /** The previous run still holds the overlap lock `scheduler:task:<id>`. */
    case StillRunning = 'still_running';

    /** Another server already claimed this minute of the task (`scheduler:task:<id>:<UTC minute>`). */
    case RanOnAnotherServer = 'ran_on_another_server';

    /**
     * The lock storage could not be reached, so no lock could be taken. The run is skipped rather
     * than run unprotected: running twice is worse than running late.
     */
    case LockUnavailable = 'lock_unavailable';

    /** A run condition (TimeWindow, Environments) did not hold at the due time. */
    case ConditionNotMet = 'condition_not_met';

    /** The engine held every run back, not this task: a Laravel schedule paused with `schedule:pause`. */
    case Paused = 'paused';
}
