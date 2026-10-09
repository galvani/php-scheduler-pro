<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Bridge\Symfony\RedisConnection;
use Scheduling\Tests\RedisConnection as TestRedis;
use Scheduling\Tests\Run\CollectingLogger;
use stdClass;

final class RedisConnectionTest extends TestCase
{
    /** @throws RedisException */
    public function testDsnConnectsAPlainClient(): void
    {
        $open = TestRedis::open();
        $logger = new CollectingLogger();

        $redis = RedisConnection::fromDsn('redis://'.$open->getHost().':'.$open->getPort(), $logger);

        self::assertTrue((bool) $redis->ping());
        self::assertSame([], $logger->records);
        self::assertContains($redis->getOption(Redis::OPT_PREFIX), [null, false, '']);
    }

    /** @throws RedisException */
    public function testDsnWithADatabaseSelectsIt(): void
    {
        $open = TestRedis::open();

        $redis = RedisConnection::fromDsn('redis://'.$open->getHost().':'.$open->getPort().'/3', new CollectingLogger());

        self::assertSame(3, $redis->getDbNum());
    }

    /** @throws RedisException */
    public function testUnreachableDsnIsLoggedWithoutThePassword(): void
    {
        $logger = new CollectingLogger();

        $redis = RedisConnection::fromDsn('redis://user:secret@127.0.0.1:1/2', $logger);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('redis://127.0.0.1:1/2', $logger->records[0]['context']['dsn']);
        $this->expectException(RedisException::class);
        $redis->ping();
    }

    #[DataProvider('badDsns')]
    public function testUnsupportedDsnIsRefused(string $dsn): void
    {
        $this->expectException(InvalidArgumentException::class);

        RedisConnection::fromDsn($dsn, new CollectingLogger());
    }

    /** @return iterable<string, array{string}> */
    public static function badDsns(): iterable
    {
        yield 'wrong scheme' => ['http://127.0.0.1:6379'];
        yield 'no host' => ['redis://'];
        yield 'not a url' => ['not a dsn'];
        yield 'options' => ['redis://127.0.0.1:6379?timeout=2'];
    }

    /** @throws RedisException */
    public function testUnprefixedReturnsTheSameClient(): void
    {
        $redis = TestRedis::open();

        self::assertSame($redis, RedisConnection::unprefixed($redis, 'service app.redis', new CollectingLogger()));
    }

    /** @throws RedisException */
    public function testPrefixedClientIsRefusedNamingSourceAndPrefix(): void
    {
        $redis = TestRedis::open();
        $redis->setOption(Redis::OPT_PREFIX, 'app:');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('service app.redis adds the key prefix "app:" to every key');

        RedisConnection::unprefixed($redis, 'service app.redis', new CollectingLogger());
    }

    public function testNonRedisObjectIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('service app.redis must be a phpredis Redis client, got stdClass');

        RedisConnection::unprefixed(new stdClass(), 'service app.redis', new CollectingLogger());
    }
}
