<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\YamlTaskSource;

final class YamlTaskSourceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/scheduling-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->directory}/*") ?: []);
        rmdir($this->directory);
    }

    public function testLoadsEveryTask(): void
    {
        $set = $this->source(<<<'YAML'
            tasks:
              report:
                schedule: "0 6 * * *"
                command: reports:send
                arguments: [weekly]
                max_runtime: 600
              cleanup:
                schedule: "0 3 * * *"
                command: cleanup
                max_runtime: 60
                enabled: false
            YAML)->load();

        self::assertSame([], $set->problems);
        self::assertSame(['report', 'cleanup'], array_keys($set->tasks));
        self::assertSame(['weekly'], $set->tasks['report']->command->arguments);
        self::assertFalse($set->tasks['cleanup']->enabled);
    }

    public function testMissingFileIsAProblem(): void
    {
        $set = new YamlTaskSource("{$this->directory}/absent.yaml", $this->mapper())->load();

        self::assertSame([], $set->tasks);
        self::assertCount(1, $set->problems);
        self::assertNull($set->problems[0]->taskId);
        self::assertSame('yaml', $set->problems[0]->source);
    }

    public function testParseErrorIsAProblem(): void
    {
        $set = $this->source("tasks: [unclosed\n  - : :")->load();

        self::assertSame([], $set->tasks);
        self::assertCount(1, $set->problems);
        self::assertNull($set->problems[0]->taskId);
    }

    public function testDocumentWithoutATasksMapIsAProblem(): void
    {
        $set = $this->source('just a string')->load();

        self::assertSame([], $set->tasks);
        self::assertCount(1, $set->problems);
    }

    public function testAMapWithoutTheTasksKeyIsAProblem(): void
    {
        $set = $this->source("task:\n  report:\n    schedule: \"0 6 * * *\"")->load();

        self::assertSame([], $set->tasks);
        self::assertCount(1, $set->problems);
    }

    public function testTasksThatIsNotAMapIsAProblem(): void
    {
        $set = $this->source('tasks: nope')->load();

        self::assertSame([], $set->tasks);
        self::assertCount(1, $set->problems);
    }

    public function testAnEmptyTasksMapLoadsNothing(): void
    {
        $set = $this->source('tasks: {}')->load();

        self::assertSame([], $set->tasks);
        self::assertSame([], $set->problems);
    }

    public function testOneBadRowDoesNotStopTheOthers(): void
    {
        $set = $this->source(<<<'YAML'
            tasks:
              good:
                schedule: "0 6 * * *"
                command: a
                max_runtime: 60
              Bad Id:
                schedule: "0 6 * * *"
                command: a
                max_runtime: 60
              bad-schedule:
                schedule: nope
                command: a
                max_runtime: 60
              unknown-key:
                schedule: "0 6 * * *"
                command: a
                max_runtime: 60
                colour: red
              not-a-map: 5
              also-good:
                schedule: "0 7 * * *"
                command: b
                max_runtime: 60
            YAML)->load();

        self::assertSame(['good', 'also-good'], array_keys($set->tasks));
        self::assertSame(
            ['Bad Id', 'bad-schedule', 'unknown-key', 'not-a-map'],
            array_map(static fn ($problem) => $problem->taskId, $set->problems),
        );
        self::assertStringContainsString('colour', $set->problems[2]->reason);
    }

    public function testARowWithAShellCommandNameOrOptionNameIsAProblemAndSkipped(): void
    {
        $set = $this->source(<<<'YAML'
            tasks:
              good:
                schedule: "0 6 * * *"
                command: a
                max_runtime: 60
              bad-command:
                schedule: "0 6 * * *"
                command: "app:hello; touch /tmp/pwned"
                max_runtime: 60
              bad-option:
                schedule: "0 6 * * *"
                command: a
                options:
                  "z; touch /tmp/pwned #": true
                max_runtime: 60
            YAML)->load();

        self::assertSame(['good'], array_keys($set->tasks));
        self::assertSame(['bad-command', 'bad-option'], array_map(static fn ($problem) => $problem->taskId, $set->problems));
        self::assertSame('yaml', $set->problems[0]->source);
        self::assertStringContainsString('Command name', $set->problems[0]->reason);
        self::assertStringContainsString('Option name', $set->problems[1]->reason);
    }

    private function source(string $yaml): YamlTaskSource
    {
        $path = "{$this->directory}/tasks.yaml";
        file_put_contents($path, $yaml);

        return new YamlTaskSource($path, $this->mapper());
    }

    private function mapper(): TaskDefinitionMapper
    {
        return new TaskDefinitionMapper(new DateTimeZone('UTC'));
    }
}
