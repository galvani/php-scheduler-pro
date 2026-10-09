<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
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
    ) {
    }

    public function name(): string
    {
        return 'laravel';
    }

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
