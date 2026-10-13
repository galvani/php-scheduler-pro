<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
use Scheduling\Task\TaskId;
use Throwable;

/**
 * The one stream every engine reports into. Each bridge translates its engine's hooks and events
 * (Laravel's before/onSuccess/onFailure and ScheduledTaskSkipped, crunz's before/after/onError,
 * when and skip) into these four calls, so monitoring is the same whatever engine runs.
 * RecordingRunListener turns them into the run record.
 *
 * started() returns the id of the run, and the engine passes that id to finished() or failed() when the same run
 * ends. Both ends of a run are reported by the same process: Laravel's foreground schedule:run, crunz's parent
 * process.
 */
interface RunListenerInterface
{
    /**
     * The run began; the engine holds the task's locks.
     *
     * @return RunId the id of this run, to pass to finished() or failed() when it ends
     */
    public function started(TaskId $id, DateTimeImmutable $at): RunId;

    /**
     * The run ended successfully.
     *
     * @param RunId $run the id started() returned for this run
     */
    public function finished(TaskId $id, RunId $run, DateTimeImmutable $at): void;

    /**
     * The run ended with an error.
     *
     * @param RunId          $run      the id started() returned for this run
     * @param int|null       $exitCode the command's exit code, when the engine reports it
     * @param Throwable|null $error    the exception, when the engine reports it
     */
    public function failed(TaskId $id, RunId $run, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void;

    /** The run was due but did not start. */
    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void;
}
