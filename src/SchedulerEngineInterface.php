<?php declare(strict_types=1);

namespace Scheduling;

use Scheduling\Capability\CapabilityReport;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskProblem;

/**
 * A scheduler that decides when a task is due and runs it: Laravel's scheduler or crunz today.
 * An implementation is a bridge: it turns each TaskDefinition into the engine's own registration,
 * plugs our mutex into the engine's lock slot and feeds the engine's hooks into RunListenerInterface.
 * The engine keeps timing and execution; swapping engines means swapping this implementation.
 */
interface SchedulerEngineInterface
{
    /**
     * The engine's name; also the key of its section in a task's adapter options (`adapters: {laravel: …}`).
     *
     * @return non-empty-string
     */
    public function name(): string;

    /** What the engine does itself, what this package adds on top, and what neither can do. */
    public function capabilities(): CapabilityReport;

    /**
     * Registers the tasks with the engine for the current tick. A disabled task is not registered.
     *
     * @param array<string, TaskDefinition> $tasks keyed by task id
     *
     * @return list<TaskProblem> tasks the engine refused, e.g. invalid adapter options; the rest are registered
     */
    public function register(array $tasks): array;
}
