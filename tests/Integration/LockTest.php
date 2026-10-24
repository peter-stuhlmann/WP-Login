<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Security\Lock;
use Portier\Security\RateLimiter;
use Portier\Security\TransientAttemptStore;
use WP_UnitTestCase;

/**
 * Locks between parallel requests and rate-limit counters that do not lose parallel failures.
 *
 * @covers \Portier\Security\Lock
 * @covers \Portier\Security\TransientAttemptStore
 */
final class LockTest extends WP_UnitTestCase
{
    public function test_only_one_request_holds_a_lock(): void
    {
        $lock = new Lock();
        $this->assertTrue($lock->acquire('a'));
        $this->assertFalse((new Lock())->acquire('a'), 'A parallel request has to wait.');
        $this->assertTrue($lock->acquire('b'), 'Names are independent.');

        $lock->release('a');
        $this->assertTrue((new Lock())->acquire('a'));
    }

    public function test_a_lock_left_by_a_crashed_request_is_taken_over_once(): void
    {
        global $wpdb;
        (new Lock())->acquire('crashed');
        $wpdb->update($wpdb->options, ['option_value' => (string) (time() - 31)], ['option_name' => 'portier_lock_' . md5('crashed')]);

        $this->assertTrue((new Lock())->acquire('crashed'));
        $this->assertFalse((new Lock())->acquire('crashed'), 'Then it is held again.');
    }

    public function test_waiting_gives_up_after_the_given_time(): void
    {
        (new Lock())->acquire('busy');
        $start = microtime(true);
        $this->assertFalse((new Lock())->acquire('busy', 0.1));
        $this->assertGreaterThanOrEqual(0.1, microtime(true) - $start);
    }

    public function test_counting_sees_failures_of_parallel_requests(): void
    {
        $limiter = new RateLimiter(new TransientAttemptStore(), 3, 900, 300, 86400);
        $this->assertSame(0, $limiter->hit('k'));
        // Meanwhile a parallel request counted another failure – this request's cache does not know.
        $this->writeBehindTheCache('k', ['attempts' => 2, 'first' => time(), 'locked_until' => 0, 'locks' => 0]);

        $this->assertSame(300, $limiter->hit('k'), 'The third failure locks: none was lost.');
    }

    /**
     * @param array<string, int> $record
     */
    private function writeBehindTheCache(string $key, array $record): void
    {
        global $wpdb;
        $name = '_site_transient_portier_rl_' . hash('sha256', $key);
        if (is_multisite()) {
            $wpdb->update($wpdb->sitemeta, ['meta_value' => maybe_serialize($record)], ['meta_key' => $name, 'site_id' => get_current_network_id()]);
        } else {
            $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($record)], ['option_name' => $name]);
        }
    }
}
