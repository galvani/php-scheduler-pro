<?php declare(strict_types=1);

namespace Scheduling\Task;

use DateTimeImmutable;
use DateTimeZone;
use Scheduling\Task\Condition\ConditionInterface;

final readonly class TaskDefinition
{
    /** @param list<ConditionInterface> $conditions all must hold for the task to run */
    public function __construct(
        public TaskId $id,
        public CronExpression $schedule,
        public Command $command,
        public DateTimeZone $timezone,
        public LockPolicy $lock,
        public bool $enabled = true,
        public AdapterOptions $options = new AdapterOptions(),
        public array $conditions = [],
    ) {}

    public function conditionsHold(): bool
    {
        $now = new DateTimeImmutable('now', $this->timezone);
        foreach ($this->conditions as $condition) {
            if (!$condition->isSatisfied($now)) {
                return false;
            }
        }

        return true;
    }
}
