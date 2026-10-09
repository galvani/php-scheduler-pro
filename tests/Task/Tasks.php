<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use DateInvalidTimeZoneException;
use DateTimeZone;
use LogicException;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\AdapterOptions;
use Scheduling\Task\Command;
use Scheduling\Task\Condition\ConditionInterface;
use Scheduling\Task\CronExpression;
use Scheduling\Task\LockPolicy;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;

final class Tasks
{
    /** @param list<ConditionInterface> $conditions */
    public static function make(string $id = 'report', array $conditions = [], bool $enabled = true, string $timezone = 'UTC'): TaskDefinition
    {
        try {
            return new TaskDefinition(
                new TaskId($id),
                new CronExpression('0 6 * * *'),
                new Command('reports:send', ['weekly'], ['queue' => 'high', 'force' => true]),
                new DateTimeZone($timezone),
                new LockPolicy(600),
                $enabled,
                new AdapterOptions(['laravel' => ['output_file' => '/tmp/x.log']]),
                $conditions,
            );
        } catch (InvalidTaskException|DateInvalidTimeZoneException $e) {
            throw new LogicException('Invalid fixture', previous: $e);
        }
    }
}
