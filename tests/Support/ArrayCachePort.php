<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;

/** A trivial in-memory CachePort — enough for the rate-limit tests. */
final class ArrayCachePort implements CachePort
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (!$this->has($key)) {
            $this->store[$key] = $callback();
        }

        return $this->store[$key];
    }

    public function increment(string $key, int $by = 1): int
    {
        $this->store[$key] = (int) ($this->store[$key] ?? 0) + $by;

        return $this->store[$key];
    }

    public function deletePattern(string $pattern): int
    {
        return 0;
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        throw new \RuntimeException('Not used by these tests.');
    }

    public function restoreLock(string $name, string $owner): Lock
    {
        throw new \RuntimeException('Not used by these tests.');
    }
}
