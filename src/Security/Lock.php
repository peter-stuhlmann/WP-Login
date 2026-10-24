<?php

declare(strict_types=1);

namespace Portier\Security;

/**
 * Mutual exclusion between parallel requests, e.g. two code checks for the same account.
 *
 * WordPress has no locking API, so this uses the technique of core's WP_Upgrader::create_lock():
 * `INSERT IGNORE` of a row in the options table, whose `option_name` is unique – exactly one
 * request can insert it. The row holds the time it was taken; a lock left behind by a crashed
 * request is taken over after STALE_SECONDS. On multisite the main site's table is used, because
 * accounts (and therefore most locked resources) are shared by all sites.
 */
final class Lock
{
    /** Option name prefix (also used by uninstall.php to remove leftovers). */
    private const PREFIX = 'portier_lock_';
    private const STALE_SECONDS = 30;
    private const RETRY_MICROSECONDS = 20000;

    /**
     * Takes the lock, waiting up to $waitSeconds for a parallel request to release it.
     */
    public function acquire(string $name, float $waitSeconds = 0.0): bool
    {
        $option = $this->option($name);
        $deadline = microtime(true) + $waitSeconds;
        while (! $this->tryAcquire($option)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(self::RETRY_MICROSECONDS);
        }
        return true;
    }

    public function release(string $name): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The lock row must never be cached.
        $wpdb->delete($this->table(), ['option_name' => $this->option($name)]);
    }

    private function tryAcquire(string $option): bool
    {
        global $wpdb;
        $table = $this->table();
        $now = (string) time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The atomic insert is the lock; a cache would defeat it.
        if ($wpdb->query($wpdb->prepare('INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, $option, $now, 'no')) === 1) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See above.
        $since = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $option));
        if ($since === null || (int) $since > (int) $now - self::STALE_SECONDS) {
            return false;
        }
        // Left behind by a crashed request: compare-and-set, so only one request takes it over.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See above.
        return $wpdb->query($wpdb->prepare('UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $table, $now, $option, $since)) === 1;
    }

    private function option(string $name): string
    {
        return self::PREFIX . md5($name);
    }

    private function table(): string
    {
        global $wpdb;
        return is_multisite() ? $wpdb->get_blog_prefix(get_main_site_id()) . 'options' : $wpdb->options;
    }
}
