<?php declare(strict_types=1);

namespace Scheduling\Task;

/**
 * Where task definitions come from: a YAML file, a database table, or several combined
 * (CompositeTaskSource). Read on every tick, so a change is live at the next one.
 */
interface TaskSourceInterface
{
    /**
     * Never throws: a source that cannot be read, or a row that is invalid, becomes a TaskProblem
     * in the set and every valid task is still returned.
     */
    public function load(): TaskSet;
}
