<?php

declare(strict_types=1);

namespace Portier\OAuth;

/**
 * The person as confirmed by a provider. `subject` is the provider's stable, unique user id –
 * accounts are linked by it, never by the (changeable) e-mail address.
 */
final class Identity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly string $email = '',
        public readonly bool $emailVerified = false,
        public readonly string $name = '',
        public readonly string $firstName = '',
        public readonly string $lastName = '',
    ) {
        if ($subject === '') {
            throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html('Identity without subject.'));
        }
    }

    /**
     * Builds an identity from standard OpenID Connect claims.
     *
     * @param array<string, mixed> $claims
     */
    public static function fromClaims(string $provider, array $claims): self
    {
        $email = is_string($claims['email'] ?? null) ? trim($claims['email']) : '';
        return new self(
            $provider,
            is_scalar($claims['sub'] ?? null) ? (string) $claims['sub'] : '',
            is_email($email) ? $email : '',
            self::truthy($claims['email_verified'] ?? false),
            self::text($claims['name'] ?? ''),
            self::text($claims['given_name'] ?? ''),
            self::text($claims['family_name'] ?? ''),
        );
    }

    /**
     * Some providers send booleans as strings ("true").
     */
    public static function truthy(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim(wp_strip_all_tags($value)), 0, 100) : '';
    }
}
