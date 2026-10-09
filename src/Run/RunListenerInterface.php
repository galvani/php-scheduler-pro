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
 */
interface RunListenerInterface
{
    /** The run began; the engine holds the task's locks. */
    public function started(TaskId $id, DateTimeImmutable $at): void;

    /** The run ended successfully. */
    public function finished(TaskId $id, DateTimeImmutable $at): void;

    /**
     * The run ended with an error.
     *
     * @param int|null       $exitCode the command's exit code, when the engine reports it
     * @param Throwable|null $error    the exception, when the engine reports it
     */
    public function failed(TaskId $id, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void;

    /** The run was due but did not start. */
    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void;
}
