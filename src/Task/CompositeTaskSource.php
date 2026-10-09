<?php declare(strict_types=1);

namespace Scheduling\Task;

/**
 * Merges several task sources into one list. An id defined in more than one source is ambiguous, so every
 * copy is skipped and a problem reported; the other tasks are unaffected. Problems from the sources pass through.
 */
final readonly class CompositeTaskSource implements TaskSourceInterface
{
    /** @param list<TaskSourceInterface> $sources order does not matter: duplicates are skipped, not overridden */
    public function __construct(private array $sources) {}

    /**
     * Never throws: a source that cannot be read, or a row that is invalid, becomes a TaskProblem
     * in the set and every valid task is still returned.
     */
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
