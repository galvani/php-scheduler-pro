<?php declare(strict_types=1);

namespace Scheduling\Tests;

use Scheduling\Capability\Capability;
use Scheduling\Capability\CapabilityReport;
use Scheduling\Capability\Fulfilment;
use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskProblem;

final class FakeEngine implements SchedulerEngineInterface
{
    /** @var array<string, TaskDefinition> */
    public array $registered = [];

    /** @param list<TaskProblem> $refusals */
    public function __construct(
        private readonly Fulfilment $conditions = Fulfilment::Native,
        private readonly array $refusals = [],
    ) {}

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
     * What the engine does itself, what this package adds on top, and what neither can do.
     */
    public function capabilities(): CapabilityReport
    {
        return new CapabilityReport([Capability::Conditions->value => $this->conditions]);
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
