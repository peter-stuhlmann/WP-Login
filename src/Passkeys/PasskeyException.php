<?php

declare(strict_types=1);

namespace Portier\Passkeys;

use RuntimeException;

/**
 * A passkey registration or login that must be refused. The reason is a fixed code for the
 * user-facing message and the tests; the exception message is only for logs.
 */
final class PasskeyException extends RuntimeException
{
    /** Data from the browser or authenticator cannot be read. */
    public const MALFORMED = 'malformed';
    /** Unknown, expired or already used challenge. */
    public const CHALLENGE = 'challenge';
    /** Signed for another website (phishing) or another domain. */
    public const ORIGIN = 'origin';
    /** The person did not confirm on the device (presence or PIN/biometrics). */
    public const VERIFICATION = 'verification';
    /** The signature does not match the stored public key. */
    public const SIGNATURE = 'signature';
    /** The signature counter went backwards: the key may have been cloned. */
    public const COUNTER = 'counter';
    /** Key type the plugin does not accept. */
    public const ALGORITHM = 'algorithm';
    /** No account with this passkey (deleted passkey or user). */
    public const UNKNOWN = 'unknown';
    /** Passkeys are switched off or not possible on this site. */
    public const DISABLED = 'disabled';
    /** Too many attempts or too many passkeys. */
    public const LIMIT = 'limit';
    /** The account may not log in (e.g. marked as spam on the network). */
    public const BLOCKED = 'blocked';

    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
