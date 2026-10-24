<?php

declare(strict_types=1);

namespace Portier\Passkeys;

/**
 * Authenticator data (WebAuthn §6.1): which website the key was used for (hash of the RP ID),
 * what the authenticator checked (flags), its signature counter and – on registration – the new
 * credential with its public key.
 */
final class AuthenticatorData
{
    public const USER_PRESENT = 0x01;
    public const USER_VERIFIED = 0x04;
    public const BACKUP_ELIGIBLE = 0x08;
    public const BACKED_UP = 0x10;
    public const ATTESTED = 0x40;

    /** Longest credential ID the specification allows. */
    private const MAX_CREDENTIAL_ID = 1023;

    private function __construct(
        public readonly string $raw,
        public readonly string $rpIdHash,
        public readonly int $flags,
        public readonly int $signCount,
        public readonly string $aaguid = '',
        public readonly string $credentialId = '',
        public readonly ?CoseKey $publicKey = null,
    ) {
    }

    /**
     * @throws PasskeyException
     */
    public static function parse(string $raw): self
    {
        if (strlen($raw) < 37) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Authenticator data too short.'));
        }
        $flags = ord($raw[32]);
        $counter = unpack('N', substr($raw, 33, 4));
        $signCount = is_array($counter) ? (int) $counter[1] : 0;
        if (($flags & self::ATTESTED) === 0) {
            return new self($raw, substr($raw, 0, 32), $flags, $signCount);
        }

        // Attested credential data: AAGUID (16), length (2), credential ID, COSE public key.
        if (strlen($raw) < 55) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Attested credential data too short.'));
        }
        $length = unpack('n', substr($raw, 53, 2));
        $idLength = is_array($length) ? (int) $length[1] : 0;
        if ($idLength < 1 || $idLength > self::MAX_CREDENTIAL_ID || strlen($raw) < 55 + $idLength + 1) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Invalid credential ID length.'));
        }
        [$cose] = Cbor::decodePrefix(substr($raw, 55 + $idLength));

        return new self(
            $raw,
            substr($raw, 0, 32),
            $flags,
            $signCount,
            substr($raw, 37, 16),
            substr($raw, 55, $idLength),
            CoseKey::fromCose($cose),
        );
    }

    public function has(int $flag): bool
    {
        return ($this->flags & $flag) === $flag;
    }
}
