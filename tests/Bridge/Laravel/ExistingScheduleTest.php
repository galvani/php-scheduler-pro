<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\BindingResolutionException;
use Psr\Log\LoggerInterface;
use RedisException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Tests\Run\CollectingLogger;

/** Tasks the app defines in its own Laravel schedule, not through this package, locked by our mutex. */
final class ExistingScheduleTest extends LaravelTestCase
{
    private const string DUE_MINUTE = '2026-03-04 14:30:00';

    /** @var list<string> */
    private array $externalKeys = [];

    /** @throws RedisException */
    protected function tearDown(): void
    {
        foreach ($this->externalKeys as $key) {
            $keys = $this->redis->keys("{$key}*");
            if ([] !== $keys && false !== $keys) {
                $this->redis->del($keys);
            }
        }
        parent::tearDown();
    }

    /** @throws BindingResolutionException */
    public function testAppTaskRunsUnderOurOverlapLockAndClaimsItsMinute(): void
    {
        $id = $this->newTaskId();
        $event = $this->appTask($id);
        $key = $this->externalKey($event);

        $this->runSchedule();

        self::assertSame("app\n", file_get_contents($this->markerFile($id)));
        self::assertSame(0, $this->redis->exists($key));
        self::assertGreaterThan(0, $this->redis->ttl("{$key}:202603041430"));
    }

    /** @throws BindingResolutionException */
    public function testSecondServerInTheSameMinuteDoesNotRunTheAppTask(): void
    {
        $id = $this->newTaskId();
        $this->appTask($id);
        $this->runSchedule();

        $this->refreshApplication();
        $this->appTask($id);
        $this->runSchedule();

        self::assertSame("app\n", file_get_contents($this->markerFile($id)));
    }

    /** @throws BindingResolutionException */
    public function testHeldLockSkipsTheAppTask(): void
    {
        $id = $this->newTaskId();
        $event = $this->appTask($id);
        $this->redis->set($this->externalKey($event), 'another-process', ['nx', 'px' => 60000]);

        $this->runSchedule();

        self::assertFileDoesNotExist($this->markerFile($id));
        self::assertSame('another-process', $this->redis->get($this->externalKey($event)));
    }

    /** @throws BindingResolutionException */
    public function testBackgroundAppTaskStaysOnLaravelsLock(): void
    {
        $event = $this->appTask($this->newTaskId())->runInBackground();
        $mutex = $this->laravel()->make(EventMutex::class);

        self::assertTrue($mutex->create($event));

        self::assertSame(0, $this->redis->exists($this->externalKey($event)));
        self::assertTrue($mutex->exists($event));
        $mutex->forget($event);
        self::assertFalse($mutex->exists($event));
    }

    /** @throws BindingResolutionException */
    public function testUnreachableLockStorageSkipsTheAppTaskAndLogs(): void
    {
        $logger = new CollectingLogger();
        $this->laravel()->instance(LoggerInterface::class, $logger);
        $this->laravel()->instance(MutexInterface::class, new class implements MutexInterface {
            /**
             * Atomic: either this call holds the key afterwards, or it returns null.
             *
             * @param positive-int $ttlSeconds the key frees itself after this, even if the holder never releases it
             *
             * @return LockInterface|null null when another holder has the key
             */
            public function acquire(LockKey $key, int $ttlSeconds): ?LockInterface
            {
                throw new LockUnavailableException('Redis is down');
            }

            /**
             * Whether any holder has the key now; used by engines that ask before they lock.
             */
            public function isLocked(LockKey $key): bool
            {
                throw new LockUnavailableException('Redis is down');
            }
        });
        $id = $this->newTaskId();
        $event = $this->appTask($id);

        $this->runSchedule();

        self::assertFileDoesNotExist($this->markerFile($id));
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['mutex' => $event->mutexName()], array_diff_key($logger->records[0]['context'], ['exception' => true]));
    }

    /**
     * Adds a task to the app's own schedule, as routes/console.php would, due at DUE_MINUTE.
     *
     * @throws BindingResolutionException
     */
    private function appTask(string $id): Event
    {
        $this->writeTasks("tasks: {}\n");
        $this->travelTo(new DateTimeImmutable(self::DUE_MINUTE.' UTC'));
        $schedule = $this->laravel()->make(Schedule::class);
        $event = $schedule->command('scheduler-test:mark', [$this->markerFile($id), '--text=app'])
            ->cron('30 14 * * *')
            ->withoutOverlapping(5)
            ->onOneServer();
        $this->externalKeys[] = $this->externalKey($event);

        return $event;
    }

    private function externalKey(Event $event): string
    {
        return LockKey::forExternal($event->mutexName())->value;
    }
}
