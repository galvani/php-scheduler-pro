<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Task\TaskSet;

/**
 * The one entry point the application uses: it owns the task list and hands it to whichever engine
 * runs it. Application code depends on this interface only, never on Laravel or crunz, which is
 * what makes the engine swappable.
 */
interface SchedulerInterface
{
    /**
     * Every task from the source, with the problems found while reading it.
     * A bad entry or an unreadable source is reported, never thrown: the other tasks still run.
     */
    public function tasks(): TaskSet;

    /**
     * Gives the current task list to an engine; tasks the engine refuses come back as problems.
     *
     * @return TaskSet the tasks handed to the engine that it did not refuse, disabled ones included (each engine
     *                 keeps them off the schedule), and every problem: from the source and from the engine
     */
    public function registerWith(SchedulerEngineInterface $engine): TaskSet;
}
