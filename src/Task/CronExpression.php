<?php declare(strict_types=1);

namespace Scheduling\Task;

use Cron\CronExpression as CronExpressionParser;
use Scheduling\Exception\InvalidTaskException;

/**
 * A cron schedule string, checked on construction by the dragonmantank/cron-expression parser.
 * The package only stores and validates it; the engine decides when the task is due.
 */
final class CronExpression
{
    public private(set) string $value {
        /** @throws InvalidTaskException */
        set => CronExpressionParser::isValidExpression($value)
            ? $value
            : throw new InvalidTaskException("Invalid cron expression: {$value}");
    }

    /**
     * @param string $value a cron expression such as "0 3 * * *"
     *
     * @throws InvalidTaskException when the parser rejects it
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
