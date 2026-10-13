<?php declare(strict_types=1);

namespace Scheduling\Run;

use LogicException;
use Scheduling\Exception\InvalidRunIdException;

/**
 * The identity of one run of a task: 16 random bytes as 32 lower-case hex characters. RunListenerInterface::started()
 * hands it out and the end of the same run passes it back, so the end is matched with its own start even when a
 * second copy of the task started in between. The start and the end record of a run carry it.
 */
final class RunId
{
    public private(set) string $value {
        /** @throws InvalidRunIdException */
        set => preg_match('/^[0-9a-f]{32}$/D', $value)
            ? $value
            : throw new InvalidRunIdException("Invalid run id: {$value}");
    }

    /**
     * @param string $value the id as stored in a run record
     *
     * @throws InvalidRunIdException when it is not 32 lower-case hex characters
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * A new random id for a run that is starting.
     */
    public static function generate(): self
    {
        try {
            return new self(bin2hex(random_bytes(16)));
        } catch (InvalidRunIdException $e) {
            throw new LogicException('A generated run id does not match its own format', previous: $e);
        }
    }
}
