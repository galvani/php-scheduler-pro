<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ProcessUtils;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Lock\LockKey;
use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\Command;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskProblem;

/**
 * The Laravel bridge. Laravel keeps deciding what is due and runs each task as an artisan command; this class
 * registers every task with Laravel's own fluent API (cron, timezone, mutex name, withoutOverlapping, onOneServer).
 * Both lock slots are answered by our mutex (LaravelEventMutex, LaravelSchedulingMutex).
 */
final class LaravelEngine implements SchedulerEngineInterface
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly TaskIndex $index,
    ) {}

    /**
     * The engine's name; also the key of its section in a task's adapter options (`adapters: {laravel: …}`).
     *
     * @return non-empty-string
     */
    public function name(): string
    {
        return 'laravel';
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
        $problems = [];
        foreach ($tasks as $task) {
            try {
                $options = LaravelOptions::fromArray($task->options->for($this->name()));
            } catch (InvalidTaskException $e) {
                $problems[] = new TaskProblem($task->id->value, 'laravel', $e->getMessage());
                continue;
            }
            if ($task->enabled) {
                $this->registerOne($task, $options);
            }
        }

        return $problems;
    }

    private function registerOne(TaskDefinition $task, LaravelOptions $options): void
    {
        $mutexName = LockKey::forTask($task->id)->value;
        $this->index->add($mutexName, $task);

        $event = $this->schedule
            ->exec(self::commandLine($task->command))
            ->cron($task->schedule->value)
            ->timezone($task->timezone)
            ->createMutexNameUsing($mutexName)
            ->withoutOverlapping((int) ceil($task->lock->ttlSeconds / 60))
            ->onOneServer();

        if (null !== $options->outputFile) {
            $event->appendOutputTo($options->outputFile);
        }
    }

    /**
     * The artisan command line of a task. It is built here and handed to Schedule::exec() without parameters,
     * because Schedule::compileParameters() leaves unquoted every value that is numeric or starts with `-`.
     * Every argument and option value is quoted with ProcessUtils::escapeArgument(); the command and option names
     * need no quoting because Command only holds letters, digits and `: _ . -` in them. A flag is `--name`, a
     * string `--name='value'`, a list one `--name='value'` per entry.
     */
    private static function commandLine(Command $command): string
    {
        $words = [Application::formatCommandString($command->name)];
        foreach ($command->arguments as $argument) {
            $words[] = ProcessUtils::escapeArgument($argument);
        }
        foreach ($command->options as $name => $value) {
            if (true === $value) {
                $words[] = "--{$name}";
                continue;
            }
            foreach ((array) $value as $item) {
                $words[] = "--{$name}=".ProcessUtils::escapeArgument($item);
            }
        }

        return implode(' ', $words);
    }
}
