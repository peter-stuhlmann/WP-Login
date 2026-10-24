<?php

declare(strict_types=1);

namespace Portier\Security;

/**
 * Stores counters as network-wide (site) transients: on multisite, accounts are shared by
 * all sites, so an attacker must not be able to reset the counter by switching sites.
 * With a persistent object cache, transients live in memory and never touch the database.
 *
 * Updates run under a {@see Lock} and read the current value, not this request's cached copy –
 * so parallel failures all count.
 */
final class TransientAttemptStore implements AttemptStore
{
    private const PREFIX = 'portier_rl_';

    /** How long an update waits for a parallel one (seconds). */
    private const LOCK_WAIT = 2.0;

    public function __construct(private readonly Lock $lock = new Lock())
    {
    }

    public function get(string $key): ?array
    {
        $value = get_site_transient($this->name($key));
        return is_array($value) ? $value : null;
    }

    public function update(string $key, callable $change, int $ttlSeconds): array
    {
        $name = $this->name($key);
        // If the lock cannot be had in time, the update still happens: a counter that is off by
        // one in a pathological case is better than an attempt that is not counted at all.
        $locked = $this->lock->acquire($name, self::LOCK_WAIT);
        try {
            self::forgetCachedValues();
            $record = $change($this->get($key));
            set_site_transient($name, $record, $ttlSeconds);
            return $record;
        } finally {
            if ($locked) {
                $this->lock->release($name);
            }
        }
    }

    public function delete(string $key): void
    {
        delete_site_transient($this->name($key));
    }

    /**
     * Keys contain IPs and logins, so they are hashed: short, fixed length and no personal data
     * readable in the options table.
     */
    private function name(string $key): string
    {
        return self::PREFIX . hash('sha256', $key);
    }

    /**
     * WordPress keeps every option it has read in a per-request cache. A value written by a
     * parallel request in the meantime would be missed – so that cache is dropped first. With a
     * persistent object cache only its in-memory copy is dropped; the cache server stays.
     */
    private static function forgetCachedValues(): void
    {
        if (! wp_using_ext_object_cache() && wp_cache_supports('flush_group')) {
            wp_cache_flush_group(is_multisite() ? 'site-options' : 'options');
            return;
        }
        if (wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }
}
