<?php

declare(strict_types=1);

namespace Portier\Security;

/**
 * CSRF protection for forms shown to logged-out visitors.
 *
 * WordPress nonces are tied to the user ID, which is 0 for every visitor, so they do not protect
 * logged-out forms (e.g. against "login CSRF", where an attacker logs the victim into the
 * attacker's account). Instead we use a signed double-submit token: a random value lives in an
 * HttpOnly cookie and the form carries HMAC(action, cookie). Without the cookie no valid token
 * can be produced. The cookie also tells us whether the browser accepts cookies at all, which
 * a login needs anyway.
 *
 * The same class issues signed timestamps used as a "time trap" against bots that submit a form
 * faster than any human could.
 */
final class FormToken
{
    public const COOKIE = 'portier_csrf';

    /** True once a new cookie value had to be generated during this request. */
    private bool $generated = false;

    public function __construct(
        private readonly string $secret,
        private ?string $cookieValue,
    ) {
    }

    /**
     * Returns the cookie value, generating one if the browser did not send a valid one.
     * Check {@see FormToken::needsCookie()} afterwards to know whether to send Set-Cookie.
     */
    public function cookieValue(): string
    {
        if (! $this->hasCookie()) {
            $this->cookieValue = bin2hex(random_bytes(32));
            $this->generated = true;
        }
        return (string) $this->cookieValue;
    }

    /**
     * Whether the response has to set the cookie (a value was generated in this request).
     */
    public function needsCookie(): bool
    {
        return $this->generated;
    }

    public function hasCookie(): bool
    {
        return $this->cookieValue !== null && preg_match('/^[a-f0-9]{64}$/', $this->cookieValue) === 1;
    }

    public function create(string $action): string
    {
        return hash_hmac('sha256', $action . '|' . $this->cookieValue(), $this->secret);
    }

    public function verify(string $action, string $token): bool
    {
        if (! $this->hasCookie() || $token === '') {
            return false;
        }
        return hash_equals($this->create($action), $token);
    }

    /**
     * Signed "form was rendered at" timestamp.
     */
    public function timestamp(string $action, ?int $now = null): string
    {
        $time = (string) ($now ?? time());
        return $time . '.' . hash_hmac('sha256', 'ts|' . $action . '|' . $time, $this->secret);
    }

    /**
     * Age in seconds of an authentic timestamp, or null if it was forged or is malformed.
     */
    public function timestampAge(string $action, string $value, ?int $now = null): ?int
    {
        $parts = explode('.', $value, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0])) {
            return null;
        }
        $expected = hash_hmac('sha256', 'ts|' . $action . '|' . $parts[0], $this->secret);
        if (! hash_equals($expected, $parts[1])) {
            return null;
        }
        return ($now ?? time()) - (int) $parts[0];
    }
}
