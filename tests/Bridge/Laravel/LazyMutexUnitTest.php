<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Closure;
use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Laravel\LazyMutex;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Task\TaskId;

/** Isolates LazyMutex: the real mutex is a mock behind a counting resolver. */
final class LazyMutexUnitTest extends TestCase
{
    private int $resolved = 0;

    /**
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     */
    public function testNothingIsResolvedUntilTheFirstLockCall(): void
    {
        $lazy = new LazyMutex($this->resolver($this->createStub(MutexInterface::class)));

        self::assertSame(0, $this->resolved);
        $lazy->isLocked(LockKey::forTask(new TaskId('report')));
        self::assertSame(1, $this->resolved);
    }

    /**
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     */
    public function testCallsGoToTheRealMutexResolvedOnce(): void
    {
        $key = LockKey::forTask(new TaskId('report'));
        $lock = $this->createStub(LockInterface::class);
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->with($key, 60)->willReturn($lock);
        $mutex->expects($this->once())->method('isLocked')->with($key)->willReturn(true);
        $lazy = new LazyMutex($this->resolver($mutex));

        self::assertSame($lock, $lazy->acquire($key, 60));
        self::assertTrue($lazy->isLocked($key));
        self::assertSame(1, $this->resolved);
    }

    /** @return Closure(): MutexInterface */
    private function resolver(MutexInterface $mutex): Closure
    {
        return function () use ($mutex): MutexInterface {
            ++$this->resolved;

            return $mutex;
        };
    }
}
