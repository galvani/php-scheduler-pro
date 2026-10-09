<?php declare(strict_types=1);

namespace Scheduling\Task;

use Cron\CronExpression as CronExpressionParser;
use Scheduling\Exception\InvalidTaskException;

final class CronExpression
{
    public private(set) string $value {
        /** @throws InvalidTaskException */
        set => CronExpressionParser::isValidExpression($value)
            ? $value
            : throw new InvalidTaskException("Invalid cron expression: {$value}");
    }

    /** @throws InvalidTaskException */
    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
