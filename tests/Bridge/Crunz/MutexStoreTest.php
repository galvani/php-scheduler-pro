<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Crunz;

use Crunz\Event;
use Crunz\Schedule;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use ReflectionException;
use ReflectionProperty;
use Scheduling\Bridge\Crunz\MutexStore;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\RedisMutex;
use Scheduling\Task\TaskId;
use Scheduling\Tests\RedisConnection;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Lock;

/** MutexStore driven the way crunz drives it: through a real Symfony Lock and a real crunz Event, against Redis. */
final class MutexStoreTest extends TestCase
{
    private const int CRUNZ_LOCK_TTL = 120;

    private Redis $redis;

    private LockKey $key;

    private TaskId $task;

    /**
     * @throws InvalidTaskException
     * @throws RedisException
     */
    protected function setUp(): void
    {
        $this->redis = RedisConnection::open();
        $this->key = LockKey::forExternal('store-'.bin2hex(random_bytes(6)));
        $this->task = new TaskId('store');
    }

    /** @throws RedisException */
    protected function tearDown(): void
    {
        $this->redis->del($this->key->value);
    }

    /**
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockExpiredException
     */
    public function testTheSameLockAcquiresTwiceAndKnowsItsLifetime(): void
    {
        $lock = $this->lock();

        self::assertTrue($lock->acquire());
        self::assertTrue($lock->acquire());
        self::assertNotNull($lock->getRemainingLifetime());
        self::assertEqualsWithDelta(self::CRUNZ_LOCK_TTL, $lock->getRemainingLifetime(), 2);
    }

    /**
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockExpiredException
     */
    public function testAnotherLockOnTheSameKeyIsRefused(): void
    {
        $holder = $this->lock();
        self::assertTrue($holder->acquire());

        self::assertFalse($this->lock()->acquire());
    }

    /**
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockExpiredException
     * @throws RedisException
     */
    public function testRefreshExtendsTheRedisTtl(): void
    {
        $lock = $this->lock();
        self::assertTrue($lock->acquire());
        $this->redis->pExpire($this->key->value, 5_000);

        $lock->refresh();

        self::assertEqualsWithDelta(self::CRUNZ_LOCK_TTL * 1000, $this->redis->pttl($this->key->value), 2_000);
    }

    /**
     * crunz takes the lock in its skip filter, takes it again in start() and refreshes it from its run loop once
     * fewer than 30 seconds remain. The second take must keep the lock and leave crunz a lifetime to refresh.
     *
     * @throws RedisException
     * @throws ReflectionException
     */
    public function testCrunzEventKeepsTheLockWhileItRuns(): void
    {
        $event = new Schedule()->run('sleep 1')->preventOverlapping($this->store());

        self::assertTrue($event->filtersPass(new DateTimeZone('UTC')));
        self::assertEqualsWithDelta(self::CRUNZ_LOCK_TTL * 1000, $this->redis->pttl($this->key->value), 2_000);
        $event->start();
        $lock = new ReflectionProperty(Event::class, 'lock')->getValue($event);
        self::assertInstanceOf(Lock::class, $lock);
        self::assertNotNull($lock->getRemainingLifetime());

        $symfonyKey = new ReflectionProperty(Lock::class, 'key')->getValue($lock);
        self::assertInstanceOf(Key::class, $symfonyKey);
        $symfonyKey->reduceLifetime(5);
        $this->redis->pExpire($this->key->value, 5_000);
        $event->refreshLock();

        self::assertEqualsWithDelta(self::CRUNZ_LOCK_TTL * 1000, $this->redis->pttl($this->key->value), 2_000);
        $event->getProcess()->wait();
        foreach ($event->afterCallbacks() as $callback) {
            $callback();
        }
        self::assertSame(0, $this->redis->exists($this->key->value));
    }

    private function store(): MutexStore
    {
        return new MutexStore(new RedisMutex($this->redis), $this->key, new CollectingListener(), $this->task);
    }

    private function lock(): Lock
    {
        return new Lock(new Key('crunz-md5'), $this->store(), self::CRUNZ_LOCK_TTL);
    }
}
