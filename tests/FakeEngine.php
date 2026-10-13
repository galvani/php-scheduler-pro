<?php declare(strict_types=1);

namespace Scheduling\Tests;

use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskProblem;

final class FakeEngine implements SchedulerEngineInterface
{
    /** @var array<string, TaskDefinition> */
    public array $registered = [];

    /** @param list<TaskProblem> $refusals */
    public function __construct(private readonly array $refusals = []) {}

    /**
     * The engine's name; also the key of its section in a task's adapter options (`adapters: {laravel: …}`).
     *
     * @return non-empty-string
     */
    public function name(): string
    {
        return 'fake';
    }

    /**
     * Registers the tasks with the engine for the current tick. A disabled task is not registered.
     *
     * @param array<string, TaskDefinition> $tasks keyed by task id
     *
     * @return list<TaskProblem> tasks the engine refused, e.g. invalid adapter options; the rest are registered
     */
    public function register(array $tasks): array
    {
        $this->registered = $tasks;

        return $this->refusals;
    }
}
