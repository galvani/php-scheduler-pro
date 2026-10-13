<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Task\TaskSet;
use Scheduling\Task\TaskSourceInterface;

/**
 * Default SchedulerInterface. Reads the task list from one source (the YAML file) and hands it to an engine.
 */
final readonly class Scheduler implements SchedulerInterface
{
    /**
     * @param TaskSourceInterface $source where the task definitions are read from
     */
    public function __construct(private TaskSourceInterface $source) {}

    /**
     * Every task from the source, with the problems found while reading it.
     * A bad entry or an unreadable source is reported, never thrown: the other tasks still run.
     *
     * Loads the source on every call; nothing is cached.
     */
    public function tasks(): TaskSet
    {
        return $this->source->load();
    }

    /**
     * Gives the current task list to an engine; tasks the engine refuses come back as problems.
     *
     * Tasks named in the engine's refusals are dropped from the returned tasks; a refusal without a task id is only
     * reported.
     *
     * @return TaskSet the tasks handed to the engine that it did not refuse, disabled ones included (each engine
     *                 keeps them off the schedule), and every problem: from the source and from the engine
     */
    public function registerWith(SchedulerEngineInterface $engine): TaskSet
    {
        $set = $this->tasks();
        $refused = $engine->register($set->tasks);

        $registered = $set->tasks;
        foreach ($refused as $problem) {
            if (null !== $problem->taskId) {
                unset($registered[$problem->taskId]);
            }
        }

        return new TaskSet($registered, [...$set->problems, ...$refused]);
    }
}
