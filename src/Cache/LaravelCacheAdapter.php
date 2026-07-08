<?php

namespace Langsys\Laravel\Cache;

use Illuminate\Contracts\Cache\Repository;
use Langsys\SDK\Cache\CacheInterface;

/**
 * Bridges the SDK's bespoke cache contract onto a Laravel cache repository so
 * the translation catalog lives in the application's configured store (redis,
 * memcached, file, array, …) instead of the SDK's own file/redis drivers.
 *
 * clear() only evicts keys written through this adapter (tracked in an index
 * entry) — never the whole Laravel store.
 */
class LaravelCacheAdapter implements CacheInterface
{
    private const INDEX_KEY = '__key_index';

    public function __construct(
        private readonly Repository $store,
        private readonly string $prefix = 'langsys:',
        private readonly int $defaultTtl = 3600,
    ) {
    }

    public function get($key)
    {
        return $this->store->get($this->prefix . $key);
    }

    public function set($key, $value, $ttl = 3600)
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $this->_indexKey($key);

        return $ttl > 0
            ? $this->store->put($this->prefix . $key, $value, $ttl)
            : $this->store->forever($this->prefix . $key, $value);
    }

    public function has($key)
    {
        return $this->store->has($this->prefix . $key);
    }

    public function delete($key)
    {
        return $this->store->forget($this->prefix . $key);
    }

    public function clear()
    {
        $index = $this->store->get($this->prefix . self::INDEX_KEY, []);

        foreach ($index as $key) {
            $this->store->forget($this->prefix . $key);
        }

        $this->store->forget($this->prefix . self::INDEX_KEY);

        return true;
    }

    private function _indexKey(string $key): void
    {
        $index = $this->store->get($this->prefix . self::INDEX_KEY, []);

        if (!in_array($key, $index, true)) {
            $index[] = $key;
            $this->store->forever($this->prefix . self::INDEX_KEY, $index);
        }
    }
}
