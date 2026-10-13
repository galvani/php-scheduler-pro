<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ProcessUtils;
use Scheduling\Capability\Capability;
use Scheduling\Capability\CapabilityReport;
use Scheduling\Capability\Fulfilment;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Lock\LockKey;
use Scheduling\Run\RunId;
use Scheduling\Run\RunListenerInterface;
use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\Command;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskProblem;

/**
 * The Laravel bridge. Laravel keeps deciding what is due and runs each task as an artisan command; this class
 * registers every task with Laravel's own fluent API (cron, timezone, mutex name, withoutOverlapping, onOneServer,
 * when, hooks). Both lock slots are answered by our mutex (LaravelEventMutex, LaravelSchedulingMutex), and the
 * hooks feed the run record: before() reports the start and keeps the run id the listener returns, onSuccess() and
 * onFailure() report the end of that same run. Both hooks run in the foreground schedule:run process; background
 * runs are not supported.
 */
final class LaravelEngine implements SchedulerEngineInterface
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly RunListenerInterface $listener,
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
     * What the engine does itself, what this package adds on top, and what neither can do.
     */
    public function capabilities(): CapabilityReport
    {
        return new CapabilityReport([
            Capability::OverlapLock->value => Fulfilment::Native,
            Capability::OneServer->value => Fulfilment::Native,
            Capability::Conditions->value => Fulfilment::Native,
            Capability::RunRecord->value => Fulfilment::Polyfilled,
            Capability::SubMinute->value => Fulfilment::Unavailable,
            Capability::MissedRunCatchUp->value => Fulfilment::Unavailable,
        ]);
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

    /**
     * Registers one task as a Laravel event. The run id lives in a variable shared by this event's hooks only.
     * An end hook that fires without a start before it reports the end under a fresh run id, which the listener
     * does not know and so never judges overran.
     */
    private function registerOne(TaskDefinition $task, LaravelOptions $options): void
    {
        $id = $task->id;
        /** @var RunId|null $run */
        $run = null;
        $mutexName = LockKey::forTask($id)->value;
        $this->index->add($mutexName, $task);

        $event = $this->schedule
            ->exec(self::commandLine($task->command))
            ->cron($task->schedule->value)
            ->timezone($task->timezone)
            ->createMutexNameUsing($mutexName)
            ->withoutOverlapping((int) ceil($task->lock->ttlSeconds / 60))
            ->onOneServer()
            ->when(fn (): bool => $task->conditionsHold())
            ->before(function () use ($id, &$run): void {
                $run = $this->listener->started($id, new DateTimeImmutable());
            })
            ->onSuccess(function () use ($id, &$run): void {
                $this->listener->finished($id, $run ?? RunId::generate(), new DateTimeImmutable());
                $run = null;
            })
            ->onFailure(function () use ($id, &$run): void {
                $this->listener->failed($id, $run ?? RunId::generate(), new DateTimeImmutable(), null, null);
                $run = null;
            });

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
