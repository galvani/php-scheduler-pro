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

    /** {@inheritDoc} */
    public function name(): string
    {
        return 'fake';
    }

    /** {@inheritDoc} */
    public function capabilities(): CapabilityReport
    {
        return new CapabilityReport([Capability::Conditions->value => $this->conditions]);
    }

    /** {@inheritDoc} */
    public function register(array $tasks): array
    {
        $this->registered = $tasks;

        return $this->refusals;
    }
}
