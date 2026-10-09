<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\BindingResolutionException;
use Scheduling\Task\Condition\Environments;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\YamlTaskSource;

final class ExportCommandTest extends LaravelTestCase
{
    private const string NOT_EXPORTED = '# Not exported:';

    /**
     * @throws BindingResolutionException
     */
    public function testExportedYamlLoadsWithoutProblemsAndMeansWhatTheAppEntriesMean(): void
    {
        $log = "{$this->directory}/reports.log";
        $this->defineAppSchedule($log);

        $tasks = $this->loadExport($this->export());

        self::assertSame(['reports-send', 'reports-send-2'], array_keys($tasks));
        $weekly = $tasks['reports-send'];
        self::assertSame('reports:send', $weekly->command->name);
        self::assertSame(['weekly', 'two words', "it's"], $weekly->command->arguments);
        self::assertSame(['queue' => 'high', 'note' => "it's", 'tag' => ['a', 'b'], 'force' => true], $weekly->command->options);
        self::assertSame('0 6 * * *', $weekly->schedule->value);
        self::assertSame('Europe/Prague', $weekly->timezone->getName());
        self::assertSame(600, $weekly->lock->maxRuntimeSeconds);
        self::assertSame(1.0, $weekly->lock->margin);
        self::assertSame(600, $weekly->lock->ttlSeconds);
        self::assertEquals([new Environments(['production'], 'testing')], $weekly->conditions);
        self::assertSame(['output_file' => $log], $weekly->options->for('laravel'));

        $daily = $tasks['reports-send-2'];
        self::assertSame('reports:send', $daily->command->name);
        self::assertSame([], $daily->command->arguments);
        self::assertSame('UTC', $daily->timezone->getName());
        self::assertSame(3600, $daily->lock->maxRuntimeSeconds);
        self::assertSame([], $daily->options->for('laravel'));
    }

    /**
     * @throws BindingResolutionException
     */
    public function testNotesAndTheNotExportedBlockNameWhatChangesOrIsLost(): void
    {
        $this->defineAppSchedule("{$this->directory}/reports.log");

        $output = $this->export();

        self::assertMatchesRegularExpression("/closures and were not exported\n(  # review.*\n)*  reports-send-2:/", $output);
        self::assertStringContainsString('# review: the app runs this in the background', $output);
        self::assertStringContainsString('# review: the app did not set withoutOverlapping', $output);
        self::assertStringContainsString("    max_runtime: 3600 # placeholder: set the real maximum runtime by hand\n", $output);
        self::assertStringContainsString("  # Weekly digest\n", $output);
        self::assertStringContainsString("    max_runtime: 600\n", $output);
        self::assertStringContainsString("    lock_margin: 1.0\n", $output);

        [, $notExported] = explode(self::NOT_EXPORTED."\n", $output);
        self::assertStringContainsString('#   * * * * *  Nightly cleanup  [closure or job]', $notExported);
        self::assertStringContainsString('#   * * * * *  App\Jobs\Prune  [closure or job]', $notExported);
        self::assertStringContainsString('#   0 * * * *  ls (list files)  [shell command]', $notExported);
        self::assertStringContainsString('(every five seconds)  [sub-minute schedule]', $notExported);
        self::assertStringContainsString('  [cannot tell whether --x takes the value y]', $notExported);
        self::assertStringNotContainsString('reports:send', $notExported);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testTheTasksOfThePackageItselfAreNotExported(): void
    {
        $own = $this->newTaskId();
        $this->writeTasks(<<<YAML
            tasks:
              {$own}:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile($own)}"]
                max_runtime: 120
            YAML);
        $this->laravel()->make(Schedule::class)->command('scheduler-test:mark')->daily();

        $output = $this->export();

        self::assertStringNotContainsString($own, $output);
        self::assertSame(['scheduler-test-mark'], array_keys($this->loadExport($output)));
    }

    /**
     * @throws BindingResolutionException
     */
    public function testAnIdTakenByAnOwnTaskIsNotReused(): void
    {
        $this->writeTasks(<<<YAML
            tasks:
              scheduler-test-mark:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile('x')}"]
                max_runtime: 120
            YAML);
        $this->laravel()->make(Schedule::class)->command('scheduler-test:mark')->daily();

        self::assertSame(['scheduler-test-mark-2'], array_keys($this->loadExport($this->export())));
    }

    /**
     * @throws BindingResolutionException
     */
    public function testNothingToExportStillPrintsTheNotExportedBlock(): void
    {
        $this->laravel()->make(Schedule::class)->exec('ls')->hourly();

        $output = $this->export();

        self::assertStringStartsWith(self::NOT_EXPORTED."\n#   0 * * * *  ls  [shell command]", $output);
        self::assertStringNotContainsString('tasks:', $output);
    }

    /**
     * @throws BindingResolutionException
     */
    private function defineAppSchedule(string $log): void
    {
        $schedule = $this->laravel()->make(Schedule::class);
        $schedule->command('reports:send', [
            'weekly',
            'two words',
            "it's",
            '--queue' => 'high',
            '--note' => "it's",
            '--tag' => ['a', 'b'],
            '--force',
        ])->cron('0 6 * * *')
            ->name('Weekly digest')
            ->withoutOverlapping(10)
            ->onOneServer()
            ->timezone('Europe/Prague')
            ->environments('production')
            ->appendOutputTo($log);
        $schedule->command('reports:send')->daily()->when(static fn (): bool => true)->runInBackground();
        $schedule->call(static fn (): null => null)->name('Nightly cleanup');
        $schedule->job('App\Jobs\Prune');
        $schedule->exec('ls')->hourly()->name('list files');
        $schedule->command('sync:run')->everyFiveSeconds()->name('every five seconds');
        $schedule->command('sync:run --x y')->daily();
    }

    /**
     * @throws BindingResolutionException
     */
    private function export(): string
    {
        $kernel = $this->laravel()->make(Kernel::class);
        self::assertSame(0, $kernel->call('scheduling:export'));

        return $kernel->output();
    }

    /**
     * @return array<string, TaskDefinition>
     */
    private function loadExport(string $yaml): array
    {
        $path = "{$this->directory}/exported.yaml";
        file_put_contents($path, $yaml);

        $set = new YamlTaskSource($path, new TaskDefinitionMapper(new DateTimeZone('UTC'), 'testing'))->load();

        self::assertSame([], $set->problems);

        return $set->tasks;
    }
}
