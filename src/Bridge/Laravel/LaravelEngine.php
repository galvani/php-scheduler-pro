<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Scheduling\Capability\Capability;
use Scheduling\Capability\CapabilityReport;
use Scheduling\Capability\Fulfilment;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Lock\LockKey;
use Scheduling\Run\RunListenerInterface;
use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\Command;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskProblem;

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

    private function registerOne(TaskDefinition $task, LaravelOptions $options): void
    {
        $id = $task->id;
        $mutexName = LockKey::forTask($id)->value;
        $this->index->add($mutexName, $task);

        $event = $this->schedule
            ->command($task->command->name, self::parameters($task->command))
            ->cron($task->schedule->value)
            ->timezone($task->timezone)
            ->createMutexNameUsing($mutexName)
            ->withoutOverlapping((int) ceil($task->lock->ttlSeconds / 60))
            ->onOneServer()
            ->when(fn (): bool => $task->conditionsHold())
            ->before(fn () => $this->listener->started($id, new DateTimeImmutable()))
            ->onSuccess(fn () => $this->listener->finished($id, new DateTimeImmutable()))
            ->onFailure(fn () => $this->listener->failed($id, new DateTimeImmutable(), null, null));

        if (null !== $options->outputFile) {
            $event->appendOutputTo($options->outputFile);
        }
    }

    /** @return array<int|string, string|list<string>> in the shape Schedule::compileParameters() reads */
    private static function parameters(Command $command): array
    {
        $parameters = $command->arguments;
        foreach ($command->options as $name => $value) {
            if (true === $value) {
                $parameters[] = "--{$name}";
            } else {
                $parameters["--{$name}"] = $value;
            }
        }

        return $parameters;
    }
}
