<?php

declare(strict_types=1);

namespace Portier\Security;

use Closure;

/**
 * Counts failures per key and locks the key with a growing (doubling) lock time.
 *
 * Example with maxAttempts=5, baseLock=5 min: five failures inside the window lock the key for
 * 5 minutes; the next five failures for 10, then 20 … up to maxLock. A success clears the key.
 * Doubling instead of a permanent lock slows attackers down drastically while never locking
 * a legitimate user out for good.
 */
final class RateLimiter
{
    /** @var Closure(): int */
    private Closure $now;

    /**
     * @param (Closure(): int)|null $now Clock, injectable for tests.
     */
    public function __construct(
        private readonly AttemptStore $store,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
        private readonly int $baseLockSeconds,
        private readonly int $maxLockSeconds,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * Seconds until the key is unlocked; 0 when it is not locked.
     */
    public function lockedFor(string $key): int
    {
        $record = $this->store->get($key);
        if ($record === null) {
            return 0;
        }
        return max(0, ($record['locked_until'] ?? 0) - ($this->now)());
    }

    /**
     * Records one failure and returns the resulting lock time in seconds (0 = not locked yet).
     */
    public function hit(string $key): int
    {
        $now = ($this->now)();
        $lock = 0;
        // One atomic step in the store: parallel failures must all count.
        $this->store->update(
            $key,
            function (?array $record) use ($now, &$lock): array {
                $record ??= ['attempts' => 0, 'first' => $now, 'locked_until' => 0, 'locks' => 0];

                // A failure while locked does not extend the lock: the request is rejected anyway.
                if (($record['locked_until'] ?? 0) > $now) {
                    $lock = $record['locked_until'] - $now;
                    return $record;
                }

                // Start a fresh counting window once the previous one has expired.
                if ($now - $record['first'] >= $this->windowSeconds) {
                    $record['attempts'] = 0;
                    $record['first'] = $now;
                }

                $record['attempts']++;
                if ($record['attempts'] >= $this->maxAttempts) {
                    $lock = (int) min($this->maxLockSeconds, $this->baseLockSeconds * (2 ** $record['locks']));
                    $record['locked_until'] = $now + $lock;
                    $record['locks']++;
                    $record['attempts'] = 0;
                    $record['first'] = $now;
                }
                return $record;
            },
            // Keep the record long enough to remember previous locks for the doubling.
            max($this->windowSeconds, $this->maxLockSeconds * 2)
        );
        return $lock;
    }

    public function clear(string $key): void
    {
        $this->store->delete($key);
    }
}
