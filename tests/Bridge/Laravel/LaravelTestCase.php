<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase;
use Redis;
use RedisException;
use Scheduling\Bridge\Laravel\SchedulingServiceProvider;
use Scheduling\Tests\RedisConnection;

abstract class LaravelTestCase extends TestCase
{
    protected Redis $redis;

    protected string $directory;

    /** @var list<string> */
    private array $taskIds = [];

    /** @throws RedisException */
    protected function setUp(): void
    {
        defined('ARTISAN_BINARY') || define('ARTISAN_BINARY', __DIR__.'/artisan.php');
        $this->redis = RedisConnection::open();
        $this->directory = sys_get_temp_dir().'/scheduling-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        parent::setUp();
    }

    /** @throws RedisException */
    protected function tearDown(): void
    {
        foreach ($this->taskIds as $id) {
            $keys = $this->redis->keys("scheduler:*{$id}*");
            if ([] !== $keys && false !== $keys) {
                $this->redis->del($keys);
            }
        }
        array_map(unlink(...), glob("{$this->directory}/*") ?: []);
        rmdir($this->directory);
        parent::tearDown();
    }

    /**
     * @param Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SchedulingServiceProvider::class];
    }

    /**
     * @param Application $app
     *
     * @throws BindingResolutionException
     */
    protected function defineEnvironment($app): void
    {
        $app->make(Config::class)->set([
            'scheduling.tasks_file' => "{$this->directory}/scheduling.yaml",
            'database.redis' => [
                'client' => 'phpredis',
                'default' => ['host' => $this->redis->getHost(), 'port' => $this->redis->getPort(), 'database' => 0],
            ],
        ]);
    }

    protected function laravel(): Application
    {
        return $this->app ?? throw new LogicException('The application is not booted');
    }

    protected function newTaskId(): string
    {
        return $this->taskIds[] = 'lv-'.bin2hex(random_bytes(5));
    }

    protected function markerFile(string $id): string
    {
        return "{$this->directory}/{$id}.marker";
    }

    protected function writeTasks(string $yaml): void
    {
        file_put_contents("{$this->directory}/scheduling.yaml", $yaml);
    }

    /** @throws BindingResolutionException */
    protected function runSchedule(): void
    {
        self::assertSame(0, $this->laravel()->make(Kernel::class)->call('schedule:run'));
    }
}
