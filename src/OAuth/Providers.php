<?php

declare(strict_types=1);

namespace Portier\OAuth;

/**
 * Registry of the available identity providers.
 */
final class Providers
{
    /** @var array<string, Provider>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, Provider> Keyed by id, in display order.
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $providers = [];
        /**
         * Filters the identity providers offered by Portier.
         *
         * @param list<Provider> $providers Built-in providers (Google, LinkedIn, Discord).
         */
        foreach ((array) apply_filters('portier_oauth_providers', self::builtIn()) as $provider) {
            if ($provider instanceof Provider) {
                $providers[$provider->id] = $provider;
            }
        }
        return self::$cache = $providers;
    }

    public static function get(string $id): ?Provider
    {
        return self::all()[$id] ?? null;
    }

    /**
     * Forgets the registry (tests and late filters).
     */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * @return list<Provider>
     */
    public static function builtIn(): array
    {
        return [
            new Provider(
                id: 'google',
                label: 'Google',
                scopes: ['openid', 'email', 'profile'],
                discovery: 'https://accounts.google.com/.well-known/openid-configuration',
                pkce: true,
                // Google issues both spellings (see its OpenID Connect documentation).
                issuers: ['accounts.google.com', 'https://accounts.google.com'],
                authorizeParams: ['prompt' => 'select_account'],
                consoleUrl: 'https://console.cloud.google.com/apis/credentials',
                docsUrl: 'https://developers.google.com/identity/openid-connect/openid-connect',
            ),
            new Provider(
                id: 'linkedin',
                label: 'LinkedIn',
                scopes: ['openid', 'email', 'profile'],
                discovery: 'https://www.linkedin.com/oauth/.well-known/openid-configuration',
                // LinkedIn documents PKCE for native apps only; state + nonce + secret protect the web flow.
                pkce: false,
                consoleUrl: 'https://www.linkedin.com/developers/apps',
                docsUrl: 'https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2',
            ),
            new Provider(
                id: 'discord',
                label: 'Discord',
                scopes: ['identify', 'email'],
                authorizeUrl: 'https://discord.com/oauth2/authorize',
                tokenUrl: 'https://discord.com/api/oauth2/token',
                userinfoUrl: 'https://discord.com/api/users/@me',
                pkce: true,
                // "none" skips the consent screen once the app is authorised.
                authorizeParams: ['prompt' => 'none'],
                mapUser: static fn (array $user): Identity => new Identity(
                    'discord',
                    is_scalar($user['id'] ?? null) ? (string) $user['id'] : '',
                    is_string($user['email'] ?? null) && is_email($user['email']) ? $user['email'] : '',
                    Identity::truthy($user['verified'] ?? false),
                    Identity::text($user['global_name'] ?? '') !== '' ? Identity::text($user['global_name']) : Identity::text($user['username'] ?? ''),
                ),
                consoleUrl: 'https://discord.com/developers/applications',
                docsUrl: 'https://discord.com/developers/docs/topics/oauth2',
            ),
        ];
    }
}
