<?php

declare(strict_types=1);

namespace Portier\OAuth;

/**
 * Describes an identity provider. Two kinds are supported:
 *
 * - OpenID Connect (`discovery` set): endpoints, issuer and signing keys come from the
 *   provider's discovery document; the identity comes from the signed ID token.
 * - Plain OAuth 2.0 (`authorizeUrl`, `tokenUrl`, `userinfoUrl` set): the identity comes from the
 *   user info endpoint and is mapped by `mapUser`.
 *
 * Further providers can be added with the `portier_oauth_providers` filter.
 */
final class Provider
{
    /**
     * @param list<string>          $scopes
     * @param list<string>          $issuers         Extra accepted `iss` values (besides the discovered one).
     * @param array<string, string> $authorizeParams Extra parameters for the authorization request.
     * @param (\Closure(array<string, mixed>): Identity)|null $mapUser For OAuth 2.0 providers.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $scopes,
        public readonly string $discovery = '',
        public readonly string $authorizeUrl = '',
        public readonly string $tokenUrl = '',
        public readonly string $userinfoUrl = '',
        public readonly bool $pkce = true,
        public readonly array $issuers = [],
        public readonly array $authorizeParams = [],
        public readonly ?\Closure $mapUser = null,
        public readonly string $consoleUrl = '',
        public readonly string $docsUrl = '',
    ) {
        if (! preg_match('/^[a-z0-9-]{2,32}$/', $id)) {
            throw new \InvalidArgumentException(esc_html('Provider ids use 2–32 lowercase letters, digits or hyphens.'));
        }
        if ($discovery === '' && ($authorizeUrl === '' || $tokenUrl === '' || $userinfoUrl === '' || $mapUser === null)) {
            throw new \InvalidArgumentException(esc_html("Provider {$id} needs a discovery URL or all OAuth 2.0 endpoints and mapUser."));
        }
    }

    public function isOpenIdConnect(): bool
    {
        return $this->discovery !== '';
    }
}
