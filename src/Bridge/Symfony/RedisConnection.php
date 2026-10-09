<?php declare(strict_types=1);

namespace Scheduling\Bridge\Symfony;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Symfony\Component\Cache\Exception\InvalidArgumentException as CacheInvalidArgumentException;

/**
 * Gives the Symfony bundle its Redis client in the application's own terms: a DSN, the string the app already uses
 * for its cache and locks (`redis://host:port`, usually `%env(REDIS_URL)%`), or an existing `Redis` service of the
 * app. Both ways end in a phpredis client without a key prefix, because a prefix would be added to every lock key
 * and servers or engines would no longer share the locks. When Redis is down while the client is built, it is
 * logged and an unconnected client is returned, so every lock call fails as LockUnavailableException and the run
 * is skipped, as on any other Redis failure.
 */
final class RedisConnection
{
    private const int DEFAULT_PORT = 6379;
    private const float CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * Connects a new phpredis client from a DSN of the form `redis://[user:password@]host[:port][/database]`;
     * `rediss://` connects over TLS with the default context. A Redis that does not answer is logged as a warning,
     * without the password, and an unconnected client is returned.
     *
     * @throws InvalidArgumentException when the DSN is not a plain redis:// or rediss:// URL
     */
    public static function fromDsn(string $dsn, LoggerInterface $logger): Redis
    {
        $parts = parse_url($dsn);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        if (!is_array($parts) || !in_array($scheme, ['redis', 'rediss'], true) || !isset($parts['host']) || '' === $parts['host']) {
            throw new InvalidArgumentException('scheduling.redis must be a DSN like redis://host:port or the id of a Redis service written @service_id');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('scheduling.redis supports only redis://[user:password@]host[:port][/database]; options are not supported, use a @service_id for a configured client');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? self::DEFAULT_PORT;
        $database = isset($parts['path']) && '' !== trim($parts['path'], '/') ? (int) trim($parts['path'], '/') : 0;
        $redis = new Redis();
        try {
            $redis->connect('rediss' === $scheme ? "tls://{$host}" : $host, $port, self::CONNECT_TIMEOUT_SECONDS);
            if (isset($parts['pass'])) {
                $redis->auth(isset($parts['user']) && '' !== $parts['user']
                    ? [urldecode($parts['user']), urldecode($parts['pass'])]
                    : urldecode($parts['pass']));
            }
            if (0 !== $database && !$redis->select($database)) {
                throw new RedisException("Cannot select database {$database}");
            }
        } catch (RedisException $e) {
            self::unreachable($logger, ['dsn' => "{$scheme}://{$host}:{$port}/{$database}", 'exception' => $e]);

            return new Redis();
        }

        return $redis;
    }

    /**
     * Returns the application's own client when it is a phpredis `Redis` without `OPT_PREFIX`. Symfony's lazy
     * client connects here, on the first read of the option: when Redis is down that is logged as a warning and an
     * unconnected client is returned.
     *
     * @param string $source what the client came from, named in the error ("service app.redis")
     *
     * @throws InvalidArgumentException when the client is not a phpredis Redis or has a key prefix
     */
    public static function unprefixed(object $client, string $source, LoggerInterface $logger): Redis
    {
        if (!$client instanceof Redis) {
            throw new InvalidArgumentException("{$source} must be a phpredis Redis client, got ".get_debug_type($client));
        }
        try {
            $prefix = $client->getOption(Redis::OPT_PREFIX);
        } catch (RedisException|CacheInvalidArgumentException $e) {
            self::unreachable($logger, ['source' => $source, 'exception' => $e]);

            return new Redis();
        }
        if (is_string($prefix) && '' !== $prefix) {
            throw new InvalidArgumentException("{$source} adds the key prefix \"{$prefix}\" to every key. The scheduler needs a Redis connection without a key prefix, so every server and engine shares the lock keys: give it its own client without a prefix and point scheduling.redis at it.");
        }

        return $client;
    }

    /**
     * Logs that Redis cannot be reached while the client is built.
     *
     * @param array<string, mixed> $context
     */
    private static function unreachable(LoggerInterface $logger, array $context): void
    {
        $logger->warning('Scheduler Redis unreachable; runs that need a lock are skipped', $context);
    }
}
