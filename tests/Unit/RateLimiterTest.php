<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\Security\RateLimiter;
use Portier\Tests\Support\InMemoryAttemptStore;

/**
 * @covers \Portier\Security\RateLimiter
 */
final class RateLimiterTest extends TestCase
{
    private int $now = 1_000_000;
    private InMemoryAttemptStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryAttemptStore();
    }

    private function limiter(int $max = 3, int $window = 900, int $base = 300, int $maxLock = 86400): RateLimiter
    {
        return new RateLimiter($this->store, $max, $window, $base, $maxLock, fn (): int => $this->now);
    }

    public function test_it_does_not_lock_below_the_threshold(): void
    {
        $limiter = $this->limiter();
        $this->assertSame(0, $limiter->hit('k'));
        $this->assertSame(0, $limiter->hit('k'));
        $this->assertSame(0, $limiter->lockedFor('k'));
    }

    public function test_it_locks_when_the_threshold_is_reached(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('k');
        $limiter->hit('k');
        $this->assertSame(300, $limiter->hit('k'));
        $this->assertSame(300, $limiter->lockedFor('k'));
    }

    public function test_lock_time_doubles_with_every_further_lock_and_is_capped(): void
    {
        $limiter = $this->limiter(1, 900, 300, 1000);
        $this->assertSame(300, $limiter->hit('k'));
        $this->now += 301;
        $this->assertSame(600, $limiter->hit('k'));
        $this->now += 601;
        $this->assertSame(1000, $limiter->hit('k'), 'Capped at maxLock instead of 1200.');
    }

    public function test_failures_during_a_lock_do_not_extend_it(): void
    {
        $limiter = $this->limiter(1);
        $limiter->hit('k');
        $this->now += 100;
        $this->assertSame(200, $limiter->hit('k'));
        $this->assertSame(200, $limiter->lockedFor('k'));
    }

    public function test_the_lock_expires(): void
    {
        $limiter = $this->limiter(1);
        $limiter->hit('k');
        $this->now += 300;
        $this->assertSame(0, $limiter->lockedFor('k'));
    }

    public function test_attempts_outside_the_window_start_a_new_count(): void
    {
        $limiter = $this->limiter(3, 900);
        $limiter->hit('k');
        $limiter->hit('k');
        $this->now += 900;
        $this->assertSame(0, $limiter->hit('k'), 'Old attempts must not count towards the new window.');
        $this->assertSame(0, $limiter->hit('k'));
        $this->assertSame(300, $limiter->hit('k'));
    }

    public function test_keys_are_independent(): void
    {
        $limiter = $this->limiter(1);
        $limiter->hit('a');
        $this->assertGreaterThan(0, $limiter->lockedFor('a'));
        $this->assertSame(0, $limiter->lockedFor('b'));
    }

    public function test_clear_removes_counters_and_locks(): void
    {
        $limiter = $this->limiter(1);
        $limiter->hit('k');
        $limiter->clear('k');
        $this->assertSame(0, $limiter->lockedFor('k'));
        $this->assertArrayNotHasKey('k', $this->store->records);
    }

    public function test_records_live_long_enough_to_remember_previous_locks(): void
    {
        $limiter = $this->limiter(3, 900, 300, 86400);
        $limiter->hit('k');
        $this->assertSame(172800, $this->store->ttls['k']);
    }
}
