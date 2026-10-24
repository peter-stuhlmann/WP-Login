<?php

declare(strict_types=1);

namespace Portier\OAuth;

/**
 * A social login that cannot continue. `reason` is a stable code that the screens turn into a
 * translated message; `getMessage()` holds technical details for logs only (never shown).
 */
final class OAuthException extends \RuntimeException
{
    /** The user cancelled at the provider. */
    public const CANCELLED = 'cancelled';
    /** State missing, reused, expired or from another browser. */
    public const STATE = 'state';
    /** Provider not reachable or answered with an error. */
    public const PROVIDER = 'provider';
    /** The ID token failed a check (signature, issuer, audience, expiry, nonce). */
    public const TOKEN = 'token';
    /** No (verified) e-mail address – needed to match or create an account. */
    public const EMAIL = 'email';
    /** No matching account and sign-up via this provider is not allowed. */
    public const NO_ACCOUNT = 'no-account';
    /** An account with this e-mail exists, but automatic linking is switched off. */
    public const LINK_DISABLED = 'link-disabled';
    /** The provider account is already connected to another WordPress account. */
    public const LINKED_ELSEWHERE = 'linked-elsewhere';
    /** The account's role may not use social login (e.g. administrators). */
    public const BLOCKED = 'blocked';
    /** Provider switched off or credentials missing. */
    public const UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $reason, string $details = '')
    {
        parent::__construct($details !== '' ? $details : $reason);
    }
}
