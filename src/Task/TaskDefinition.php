<?php declare(strict_types=1);

namespace Scheduling\Task;

use DateTimeImmutable;
use DateTimeZone;
use Scheduling\Task\Condition\ConditionInterface;

/**
 * One scheduled task: what runs, when, in which timezone, under which lock, and with which extra conditions.
 * An immutable value object; the same shape is stored as a YAML entry or a database row (see TaskDefinitionMapper).
 */
final readonly class TaskDefinition
{
    /**
     * @param TaskId                   $id         unique across all sources
     * @param CronExpression           $schedule   when the engine considers the task due
     * @param Command                  $command    what to run
     * @param DateTimeZone             $timezone   the zone the schedule and the conditions are evaluated in
     * @param LockPolicy               $lock       runtime limit and lock lifetime
     * @param bool                     $enabled    false keeps the task but stops it running
     * @param AdapterOptions           $options    per-engine settings, passed through
     * @param list<ConditionInterface> $conditions all must hold for the task to run
     */
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

    /**
     * Whether every condition holds right now, evaluated at the current time in the task's timezone.
     * True when there are no conditions.
     */
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
