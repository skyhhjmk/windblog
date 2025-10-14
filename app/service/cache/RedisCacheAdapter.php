<?php

namespace app\service\cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use support\Redis;

class RedisCacheAdapter implements CacheInterface
{
    private string $connection;

    private string $prefix;

    private int $defaultTtl;

    public function __construct(array $options = [])
    {
        $this->connection = $options['connection'] ?? 'default';
        $this->prefix = $options['prefix'] ?? 'twig:fragment:';
        $this->defaultTtl = (int) ($options['default_ttl'] ?? 300);
    }

    private function conn()
    {
        return Redis::connection($this->connection);
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }

    private function normTtl($ttl): int
    {
        if ($ttl instanceof DateInterval) {
            $now = new \DateTimeImmutable();
            $future = (new \DateTimeImmutable())->add($ttl);

            return max(0, $future->getTimestamp() - $now->getTimestamp());
        }
        if (is_int($ttl)) {
            return max(0, $ttl);
        }

        return $this->defaultTtl;
    }

    private function encode($value): string
    {
        return serialize($value);
    }

    private function decode(?string $payload)
    {
        if ($payload === null) {
            return null;
        }
        try {
            return unserialize($payload);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $val = $this->conn()->get($this->k($key));

        return $val === null ? $default : $this->decode($val);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $ttlSec = $this->normTtl($ttl);
        $payload = $this->encode($value);
        $keyName = $this->k($key);
        if ($ttlSec > 0) {
            return (bool) $this->conn()->setex($keyName, $ttlSec, $payload);
        }

        return (bool) $this->conn()->set($keyName, $payload);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->conn()->del($this->k($key));
    }

    public function clear(): bool
    {
        // 仅按前缀清理，避免误删其他业务键
        $cursor = 0;
        $conn = $this->conn();
        do {
            $result = $conn->scan($cursor, ['match' => $this->prefix . '*', 'count' => 1000]);
            $cursor = $result[0] ?? 0;
            $keys = $result[1] ?? [];
            if (!empty($keys)) {
                $conn->del(...$keys);
            }
        } while ($cursor != 0);

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $prefixed = [];
        foreach ($keys as $k) {
            $prefixed[] = $this->k((string) $k);
        }
        $vals = $this->conn()->mget($prefixed);
        $out = [];
        $i = 0;
        foreach ($keys as $k) {
            $out[$k] = ($vals[$i] ?? null) === null ? $default : $this->decode($vals[$i]);
            $i++;
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ttlSec = $this->normTtl($ttl);
        $conn = $this->conn();
        foreach ($values as $k => $v) {
            $key = $this->k((string) $k);
            $payload = $this->encode($v);
            if ($ttlSec > 0) {
                $conn->setex($key, $ttlSec, $payload);
            } else {
                $conn->set($key, $payload);
            }
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $prefixed = [];
        foreach ($keys as $k) {
            $prefixed[] = $this->k((string) $k);
        }
        if (!empty($prefixed)) {
            $this->conn()->del(...$prefixed);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return (bool) $this->conn()->exists($this->k($key));
    }
}
