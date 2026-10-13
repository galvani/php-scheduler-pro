<?php declare(strict_types=1);

namespace Scheduling\Run;

/** What one run record says happened to a task. */
enum RunState: string
{
    /** The engine started the run: it held the overlap lock and its one-server claim. */
    case Started = 'started';

    /** The run ended successfully within its max_runtime. */
    case Finished = 'finished';

    /** The run ended with an error within its max_runtime. */
    case Failed = 'failed';

    /** The run was due but did not start; SkipReason says why. */
    case Skipped = 'skipped';

    /**
     * The run ended, successfully or not, more than its max_runtime after its own start, even when another copy of
     * the task started later. Its lock may have expired while it ran,
     * so a second copy could have started beside it. It is kept apart from failed, so a retry of failed
     * runs (designed, not built) can never start another copy beside one still going.
     */
    case Overran = 'overran';
}
