<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Crunz;

use DateTimeImmutable;
use Scheduling\Run\RunId;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;
use Scheduling\Task\TaskId;
use Throwable;

final class CollectingListener implements RunListenerInterface
{
    /** @var list<string> "<event>:<task id>" or "skipped:<task id>:<reason>" */
    public array $calls = [];

    /** @var list<string> the run id each started, finished and failed call returned or received, in call order */
    public array $runIds = [];

    /**
     * The run began; the engine holds the task's locks.
     *
     * @return RunId the id of this run, to pass to finished() or failed() when it ends
     */
    public function started(TaskId $id, DateTimeImmutable $at): RunId
    {
        $this->calls[] = "started:{$id->value}";
        $run = RunId::generate();
        $this->runIds[] = $run->value;

        return $run;
    }

    /**
     * The run ended successfully.
     *
     * @param RunId $run the id started() returned for this run
     */
    public function finished(TaskId $id, RunId $run, DateTimeImmutable $at): void
    {
        $this->calls[] = "finished:{$id->value}";
        $this->runIds[] = $run->value;
    }

    /**
     * The run ended with an error.
     *
     * @param RunId          $run      the id started() returned for this run
     * @param int|null       $exitCode the command's exit code, when the engine reports it
     * @param Throwable|null $error    the exception, when the engine reports it
     */
    public function failed(TaskId $id, RunId $run, DateTimeImmutable $at, ?int $exitCode, ?Throwable $error): void
    {
        $this->calls[] = "failed:{$id->value}";
        $this->runIds[] = $run->value;
    }

    /**
     * The run was due but did not start.
     */
    public function skipped(TaskId $id, DateTimeImmutable $at, SkipReason $reason): void
    {
        $this->calls[] = "skipped:{$id->value}:{$reason->value}";
    }
}
