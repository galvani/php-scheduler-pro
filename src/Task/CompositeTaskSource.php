<?php declare(strict_types=1);

namespace Scheduling\Task;

final readonly class CompositeTaskSource implements TaskSourceInterface
{
    /** @param list<TaskSourceInterface> $sources */
    public function __construct(private array $sources) {}

    /** {@inheritDoc} */
    public function load(): TaskSet
    {
        $seen = [];
        $problems = [];
        foreach ($this->sources as $source) {
            $set = $source->load();
            array_push($problems, ...$set->problems);
            foreach ($set->tasks as $id => $task) {
                $seen[$id][] = $task;
            }
        }

        $tasks = [];
        foreach ($seen as $id => $copies) {
            if (count($copies) > 1) {
                $problems[] = new TaskProblem($id, 'composite', 'Task id defined in more than one source; all copies skipped');
                continue;
            }
            $tasks[$id] = $copies[0];
        }

        return new TaskSet($tasks, $problems);
    }
}
