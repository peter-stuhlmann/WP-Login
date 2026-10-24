<?php

declare(strict_types=1);

namespace Portier\Tests\Support;

use Portier\Security\AttemptStore;

/**
 * Test double for rate-limit storage (TTL is recorded but not enforced).
 */
final class InMemoryAttemptStore implements AttemptStore
{
    /** @var array<string, array<string, int>> */
    public array $records = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public function get(string $key): ?array
    {
        return $this->records[$key] ?? null;
    }

    public function update(string $key, callable $change, int $ttlSeconds): array
    {
        $this->records[$key] = $change($this->get($key));
        $this->ttls[$key] = $ttlSeconds;
        return $this->records[$key];
    }

    public function delete(string $key): void
    {
        unset($this->records[$key], $this->ttls[$key]);
    }
}
