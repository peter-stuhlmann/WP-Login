<?php

declare(strict_types=1);

namespace Portier\OAuth;

use Portier\Security\Crypto;
use Portier\Settings\Settings;

/**
 * Settings and credentials of one provider.
 *
 * Credentials can live in wp-config.php (recommended for the secret, it then never touches the
 * database): PORTIER_GOOGLE_CLIENT_ID / PORTIER_GOOGLE_CLIENT_SECRET, PORTIER_LINKEDIN_…, etc.
 * Secrets entered in the admin are stored encrypted ({@see Crypto}).
 */
final class ProviderConfig
{
    /** Link an existing account when the provider confirms the same e-mail address. */
    public const LINK_VERIFIED = 'verified';
    /** Never link automatically – people connect providers in their profile. */
    public const LINK_NEVER = 'never';

    /**
     * @param array{enabled: bool, client_id: string, client_secret: string, link: string, signup: bool} $values
     */
    private function __construct(
        public readonly Provider $provider,
        private readonly array $values,
        private readonly Crypto $crypto,
    ) {
    }

    public static function for(Provider $provider, Settings $settings, Crypto $crypto): self
    {
        $all = (array) $settings->get('providers');
        return new self($provider, Settings::providerValues((array) ($all[$provider->id] ?? [])), $crypto);
    }

    public function enabled(): bool
    {
        return $this->values['enabled'];
    }

    /**
     * Enabled and complete – only then a button is shown.
     */
    public function usable(): bool
    {
        return $this->enabled() && $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        return self::constant($this->provider->id, 'CLIENT_ID') ?? $this->values['client_id'];
    }

    public function clientSecret(): string
    {
        $constant = self::constant($this->provider->id, 'CLIENT_SECRET');
        if ($constant !== null) {
            return $constant;
        }
        $stored = $this->values['client_secret'];
        return $stored === '' ? '' : (string) $this->crypto->decrypt($stored);
    }

    public function linkPolicy(): string
    {
        return $this->values['link'];
    }

    public function signupAllowed(): bool
    {
        return $this->values['signup'];
    }

    /**
     * Where a credential comes from: "constant", "option" or "" (missing). For the admin.
     */
    public function source(string $credential): string
    {
        if (self::constant($this->provider->id, $credential === 'client_secret' ? 'CLIENT_SECRET' : 'CLIENT_ID') !== null) {
            return 'constant';
        }
        return $this->values[$credential === 'client_secret' ? 'client_secret' : 'client_id'] !== '' ? 'option' : '';
    }

    /**
     * Value of e.g. PORTIER_GOOGLE_CLIENT_SECRET, if defined and not empty.
     */
    public static function constant(string $providerId, string $suffix): ?string
    {
        $name = 'PORTIER_' . strtoupper(str_replace('-', '_', $providerId)) . '_' . $suffix;
        if (! defined($name)) {
            return null;
        }
        $value = constant($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
