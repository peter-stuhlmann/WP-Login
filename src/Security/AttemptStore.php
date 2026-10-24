<?php

declare(strict_types=1);

namespace Portier\Security;

/**
 * Persistence for rate-limit counters. Kept as an interface so the limiter can be unit
 * tested with an in-memory store and run on transients (or the object cache) in production.
 */
interface AttemptStore
{
    /**
     * @return array<string, int>|null
     */
    public function get(string $key): ?array;

    /**
     * Reads, changes and writes a record as one step: parallel requests changing the same key
     * must not overwrite each other's changes (otherwise a burst of failures would count once).
     *
     * @param callable(array<string, int>|null): array<string, int> $change Gets the current record.
     * @return array<string, int> The stored record.
     */
    public function update(string $key, callable $change, int $ttlSeconds): array;

    public function delete(string $key): void;
}
