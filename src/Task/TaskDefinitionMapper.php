<?php declare(strict_types=1);

namespace Scheduling\Task;

use DateInvalidTimeZoneException;
use DateTimeZone;
use Scheduling\Exception\InvalidTaskException;

/**
 * Builds a task definition from its data form, the array shape of a YAML entry.
 * Keys: schedule, command, arguments, options, timezone, max_runtime, lock_margin, enabled, adapters.
 * An unknown key is an error. `schedule`, `command` and `max_runtime` are required.
 */
final readonly class TaskDefinitionMapper
{
    private const array KEYS = [
        'schedule', 'command', 'arguments', 'options', 'timezone', 'max_runtime',
        'lock_margin', 'enabled', 'adapters',
    ];

    private const float DEFAULT_MARGIN = 1.5;

    /**
     * @param DateTimeZone $defaultTimezone used when a row has no `timezone`
     */
    public function __construct(private DateTimeZone $defaultTimezone) {}

    /**
     * Builds a validated task from a data row; defaults: lock_margin 1.5, enabled true.
     *
     * @param string                  $id  the task id, taken from the YAML key, not from the row
     * @param array<array-key, mixed> $row
     *
     * @throws InvalidTaskException when the row has an unknown key, a missing required key or a value of the wrong type or range
     */
    public function fromArray(string $id, array $row): TaskDefinition
    {
        $unknown = array_diff(array_map(strval(...), array_keys($row)), self::KEYS);
        if ([] !== $unknown) {
            throw new InvalidTaskException('Unknown key: '.implode(', ', $unknown));
        }

        return new TaskDefinition(
            new TaskId($id),
            new CronExpression($this->requiredString($row, 'schedule')),
            new Command(
                $this->requiredString($row, 'command'),
                array_key_exists('arguments', $row) ? $this->stringList($row['arguments'], 'arguments') : [],
                array_key_exists('options', $row) ? $this->options($row['options']) : [],
            ),
            $this->timezone($row),
            new LockPolicy($this->maxRuntime($row), $this->margin($row)),
            $this->enabled($row),
            new AdapterOptions(array_key_exists('adapters', $row) ? $this->adapters($row['adapters']) : []),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @throws InvalidTaskException
     */
    private function requiredString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row)) {
            throw new InvalidTaskException("{$key} is required");
        }
        if (!is_string($row[$key])) {
            throw new InvalidTaskException("{$key} must be a string");
        }

        return $row[$key];
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @throws InvalidTaskException
     */
    private function maxRuntime(array $row): int
    {
        if (!array_key_exists('max_runtime', $row)) {
            throw new InvalidTaskException('max_runtime is required');
        }
        if (!is_int($row['max_runtime'])) {
            throw new InvalidTaskException('max_runtime must be an integer');
        }

        return $row['max_runtime'];
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @throws InvalidTaskException
     */
    private function margin(array $row): float
    {
        if (!array_key_exists('lock_margin', $row)) {
            return self::DEFAULT_MARGIN;
        }
        if (!is_int($row['lock_margin']) && !is_float($row['lock_margin'])) {
            throw new InvalidTaskException('lock_margin must be a number');
        }

        return (float) $row['lock_margin'];
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @throws InvalidTaskException
     */
    private function enabled(array $row): bool
    {
        if (!array_key_exists('enabled', $row)) {
            return true;
        }
        if (!is_bool($row['enabled'])) {
            throw new InvalidTaskException('enabled must be a boolean');
        }

        return $row['enabled'];
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @throws InvalidTaskException
     */
    private function timezone(array $row): DateTimeZone
    {
        if (!array_key_exists('timezone', $row)) {
            return $this->defaultTimezone;
        }
        if (!is_string($row['timezone'])) {
            throw new InvalidTaskException('timezone must be a string');
        }
        try {
            return new DateTimeZone($row['timezone']);
        } catch (DateInvalidTimeZoneException $e) {
            throw new InvalidTaskException("Unknown timezone: {$row['timezone']}", previous: $e);
        }
    }

    /**
     * @return list<string>
     *
     * @throws InvalidTaskException
     */
    private function stringList(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidTaskException("{$key} must be a list of strings");
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidTaskException("{$key} must be a list of strings");
            }
        }

        return $value;
    }

    /**
     * @return array<non-empty-string, string|list<string>|true>
     *
     * @throws InvalidTaskException
     */
    private function options(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidTaskException('options must be a map');
        }
        $options = [];
        foreach ($value as $name => $option) {
            if (!is_string($name) || '' === $name) {
                throw new InvalidTaskException('options must be keyed by non-empty names');
            }
            $options[$name] = true === $option || is_string($option) ? $option : $this->stringList($option, "options.{$name}");
        }

        return $options;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidTaskException
     */
    private function adapters(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidTaskException('adapters must be a map');
        }
        $adapters = [];
        foreach ($value as $adapter => $options) {
            if (!is_string($adapter) || !is_array($options)) {
                throw new InvalidTaskException('adapters must map adapter names to option maps');
            }
            foreach ($options as $name => $option) {
                if (!is_string($name)) {
                    throw new InvalidTaskException("adapters.{$adapter} must be keyed by option names");
                }
                $adapters[$adapter][$name] = $option;
            }
            $adapters[$adapter] ??= [];
        }

        return $adapters;
    }
}
