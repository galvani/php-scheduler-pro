<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs a real artisan binary of an app with the package installed (`scheduling-artisan.php`), outside the unit-test
 * environment, where Laravel resolves the schedule on every artisan command.
 */
final class ArtisanProcessTest extends TestCase
{
    private string $tasksFile;

    protected function setUp(): void
    {
        $this->tasksFile = sys_get_temp_dir().'/scheduling-artisan-'.bin2hex(random_bytes(6)).'.yaml';
        file_put_contents($this->tasksFile, <<<'YAML'
            tasks:
              artisan-process-good:
                schedule: "30 14 * * *"
                command: inspire
                max_runtime: 120
              artisan-process-bad:
                schedule: "30 14 * * *"
                command: inspire
            YAML);
    }

    protected function tearDown(): void
    {
        unlink($this->tasksFile);
    }

    /** @throws RuntimeException */
    public function testACommandThatDoesNotRunTheScheduleNeitherReadsTasksNorTouchesRedis(): void
    {
        $process = $this->artisan('list', 'not-configured');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('not configured', $process->getErrorOutput());
        self::assertStringNotContainsString('max_runtime is required', $process->getErrorOutput());
    }

    /** @throws RuntimeException */
    public function testAScheduleCommandReportsAMisconfiguredRedisConnection(): void
    {
        $process = $this->artisan('schedule:list', 'not-configured');

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('Redis connection [not-configured] not configured.', $process->getOutput().$process->getErrorOutput());
    }

    /** @throws RuntimeException */
    public function testAScheduleCommandSeesThePackageTasks(): void
    {
        $process = $this->artisan('schedule:list', 'scheduler');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('inspire', $process->getOutput());
        self::assertStringContainsString('max_runtime is required', $process->getErrorOutput());
    }

    /** @throws RuntimeException */
    private function artisan(string $command, string $redisConnection): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/scheduling-artisan.php', $command], env: [
            'APP_ENV' => 'production',
            'LOG_CHANNEL' => 'stderr',
            'SCHEDULING_TASKS_FILE' => $this->tasksFile,
            'SCHEDULING_REDIS_CONNECTION' => $redisConnection,
        ]);
        $process->run();

        return $process;
    }
}
