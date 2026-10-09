<?php declare(strict_types=1);

namespace Scheduling\Tests;

use Redis;
use RedisException;

final class RedisConnection
{
    /** @throws RedisException */
    public static function open(): Redis
    {
        $host = getenv('REDIS_HOST');
        $port = getenv('REDIS_PORT');
        $redis = new Redis();
        $redis->connect(false !== $host ? $host : '127.0.0.1', false !== $port ? (int) $port : 16379, 2.0);
        $redis->ping();

        return $redis;
    }
}
