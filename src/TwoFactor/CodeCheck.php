<?php

declare(strict_types=1);

namespace Portier\TwoFactor;

/**
 * Outcome of checking a second-factor code ({@see TwoFactorService::check()}).
 */
final class CodeCheck
{
    public const VALID = 'valid';
    /** Wrong code; `retryAfter` > 0 when this very code started a pause. */
    public const WRONG = 'wrong';
    /** Too many wrong codes for this account: no code is checked for `retryAfter` seconds. */
    public const LOCKED = 'locked';
    /** A parallel check for the same account is still running. */
    public const BUSY = 'busy';
    /** The stored secret cannot be decrypted (key or salts changed) – an administrator must reset 2FA. */
    public const UNREADABLE = 'unreadable';

    private function __construct(
        public readonly string $status,
        public readonly int $retryAfter = 0,
    ) {
    }

    public static function valid(): self
    {
        return new self(self::VALID);
    }

    public static function wrong(int $retryAfter = 0): self
    {
        return new self(self::WRONG, $retryAfter);
    }

    public static function locked(int $retryAfter): self
    {
        return new self(self::LOCKED, $retryAfter);
    }

    public static function busy(): self
    {
        return new self(self::BUSY);
    }

    public static function unreadable(): self
    {
        return new self(self::UNREADABLE);
    }

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }

    /**
     * Whether the account is paused now (already before or because of this code).
     */
    public function isPaused(): bool
    {
        return $this->retryAfter > 0;
    }
}
