<?php

namespace Langsys\Laravel\Cache;

use Illuminate\Contracts\Cache\Repository;
use Langsys\SDK\Cache\CacheInterface;

/**
 * Bridges the SDK's bespoke cache contract onto a Laravel cache repository so
 * the translation catalog lives in the application's configured store (redis,
 * memcached, file, array, …) instead of the SDK's own file/redis drivers.
 *
 * clear() only evicts keys written through this adapter for its project
 * (tracked in an index entry) — never the whole Laravel store.
 */
class LaravelCacheAdapter implements CacheInterface
{
    private const INDEX_KEY = '__key_index';

    /**
     * @param ?string $projectId Scopes the key index clear() walks. Pass it, as
     *                           the service provider does: without it every
     *                           project sharing this store and prefix shares one
     *                           index, so clearing one project's cache evicts
     *                           another's catalog (CACHE-1).
     */
    public function __construct(
        private readonly Repository $store,
        private readonly string $prefix = 'langsys:',
        private readonly int $defaultTtl = 3600,
        private readonly ?string $projectId = null,
    ) {
    }

    public function get($key)
    {
        return $this->store->get($this->prefix . $key);
    }

    /**
     * $ttl defaults to null rather than CacheInterface's 3600: the SDK writes
     * its catalog without passing one, so matching the interface default meant
     * the configured TTL was never used.
     */
    public function set($key, $value, $ttl = null)
    {
        $ttl ??= $this->defaultTtl;
        $this->_track($key);

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
        $index = $this->store->get($this->_indexKey(), []);

        foreach ($index as $key) {
            $this->store->forget($this->prefix . $key);
        }

        $this->store->forget($this->_indexKey());

        return true;
    }

    private function _track(string $key): void
    {
        $index = $this->store->get($this->_indexKey(), []);

        if (!in_array($key, $index, true)) {
            $index[] = $key;
            $this->store->forever($this->_indexKey(), $index);
        }
    }

    private function _indexKey(): string
    {
        return $this->prefix . self::INDEX_KEY . ($this->projectId === null ? '' : ':' . $this->projectId);
    }
}
