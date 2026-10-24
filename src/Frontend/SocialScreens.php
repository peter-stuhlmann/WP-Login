<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Auth\LoginFlow;
use Portier\Auth\RecentLogin;
use Portier\Auth\RedirectResolver;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\OAuth\OAuthClient;
use Portier\OAuth\OAuthException;
use Portier\OAuth\Provider;
use Portier\OAuth\ProviderConfig;
use Portier\OAuth\Providers;
use Portier\OAuth\SocialAccounts;
use Portier\OAuth\StateStore;
use Portier\Routing\LoginRoute;
use Portier\Security\Crypto;
use Portier\Security\RateLimiter;
use Portier\TwoFactor\TwoFactorService;
use WP_User;

/**
 * Social login: sends people to the provider and handles their return.
 *
 * Two modes share the flow: "login" (from the login screen) and "link" (a logged-in user
 * connects a provider in their profile). Problems never show technical details; people land on
 * the login screen (or their profile) with a clear message and can choose another method.
 */
final class SocialScreens
{
    public const LINK_NONCE = 'portier-social-link';

    public function __construct(
        private readonly Responder $responder,
        private readonly OAuthClient $client,
        private readonly StateStore $states,
        private readonly SocialAccounts $accounts,
        private readonly LoginFlow $flow,
        private readonly Crypto $crypto,
        private readonly RateLimiter $limiter,
        private readonly string $clientIp,
        private readonly RecentLogin $recentLogin,
    ) {
    }

    /**
     * Completes the request of a provider callback with what WordPress hides from $_GET:
     * the provider from the path (/login/oauth/<provider>/) and the `error` parameter, which
     * WP::parse_request() deletes but providers use to report cancellations (RFC 6749, 4.1.2.1).
     *
     * @param string $pathProvider Provider id from the rewrite rule ('' if none).
     * @param string $rawQuery     The original query string ($_SERVER['QUERY_STRING']).
     */
    public static function callbackRequest(Request $request, string $pathProvider, string $rawQuery): Request
    {
        if ($pathProvider !== '') {
            $request = $request->withQuery([LoginRoute::PROVIDER_VAR => $pathProvider]);
        }
        if ($request->query(LoginRoute::PROVIDER_VAR) === '') {
            return $request;
        }
        $raw = [];
        parse_str($rawQuery, $raw);
        if (isset($raw['error']) && is_string($raw['error'])) {
            $request = $request->withQuery(['error' => sanitize_key($raw['error'])]);
        }
        return $request;
    }

    /**
     * Buttons for the login screen: every enabled provider with complete credentials.
     *
     * @return list<array{id: string, label: string, url: string}>
     */
    public function buttons(string $redirectTo): array
    {
        $buttons = [];
        foreach (Providers::all() as $id => $provider) {
            if ($this->config($provider)->usable()) {
                $buttons[] = [
                    'id' => $id,
                    'label' => $provider->label,
                    'url' => $this->responder->route()->url('oauth', array_filter(['provider' => $id, 'redirect_to' => $redirectTo])),
                ];
            }
        }
        return $buttons;
    }

    /**
     * Step 1: remember the request and send the browser to the provider.
     */
    public function start(Request $request): Response
    {
        $provider = Providers::get($request->query('provider'));
        $linking = $request->query('link') === '1';
        if ($provider === null || ! $this->config($provider)->usable()) {
            return $this->fail($request, OAuthException::UNAVAILABLE, $provider, $linking);
        }

        // Each start stores a small record; limit them per IP.
        $key = 'oauth-start|' . $this->clientIp;
        if ($this->limiter->lockedFor($key) > 0) {
            return $this->responder->toLogin($request, 'oauth-limit');
        }
        $this->limiter->hit($key);

        $data = [
            'provider' => $provider->id,
            'mode' => 'login',
            'user' => 0,
            'nonce' => bin2hex(random_bytes(16)),
            'verifier' => OAuthClient::verifier(),
            'redirect' => RedirectResolver::sanitizeRequested($request->query('redirect_to')),
            // Carried over from the "Keep me logged in" checkbox by the enhancement script.
            'remember' => $request->query('rememberme') !== '' ? 1 : 0,
        ];
        if ($linking) {
            $user = wp_get_current_user();
            if (! $user->exists() || ! wp_verify_nonce($request->query('_wpnonce'), self::LINK_NONCE)) {
                return $this->fail($request, OAuthException::STATE, $provider, true);
            }
            if (! $this->accounts->isAllowed($user, $provider->id)) {
                return $this->fail($request, OAuthException::BLOCKED, $provider, true);
            }
            // A connected account is a permanent way in: an older session logs in again first.
            if (! $this->recentLogin->isRecent()) {
                return Response::redirect($this->recentLogin->confirmUrl(admin_url('profile.php') . '#portier-social'), 303);
            }
            $data['mode'] = 'link';
            $data['user'] = $user->ID;
        }

        $config = $this->config($provider);
        try {
            $state = $this->states->create($data);
            $url = $this->client->authorizationUrl(
                $provider,
                $config->clientId(),
                $this->responder->route()->callbackUrl($provider->id),
                $state,
                (string) $data['nonce'],
                (string) $data['verifier']
            );
        } catch (OAuthException $exception) {
            return $this->fail($request, $exception->reason, $provider, $linking, $exception);
        }
        return $this->responder->withCookie(Response::external($url), StateStore::COOKIE, $state, time() + StateStore::TTL);
    }

    /**
     * Step 2: the provider sends the browser back with a code (or an error).
     */
    public function callback(Request $request): Response
    {
        $provider = Providers::get($request->query('provider'));
        $data = $this->states->consume($request->query('state'), (string) $request->cookie(StateStore::COOKIE));
        $linking = ($data['mode'] ?? '') === 'link';

        try {
            if ($provider === null) {
                throw new OAuthException(esc_html(OAuthException::UNAVAILABLE));
            }
            if ($request->query('error') !== '') {
                // access_denied: the person cancelled. Anything else is a provider problem.
                $error = sanitize_key($request->query('error'));
                throw new OAuthException(esc_html($error === 'access_denied' ? OAuthException::CANCELLED : OAuthException::PROVIDER), esc_html('Provider error: ' . $error));
            }
            if ($data === null || $data['provider'] !== $provider->id || $request->query('code') === '') {
                throw new OAuthException(esc_html(OAuthException::STATE));
            }
            $config = $this->config($provider);
            if (! $config->usable()) {
                throw new OAuthException(esc_html(OAuthException::UNAVAILABLE));
            }

            $tokens = $this->client->exchange(
                $provider,
                $config->clientId(),
                $config->clientSecret(),
                $request->query('code'),
                $this->responder->route()->callbackUrl($provider->id),
                (string) $data['verifier']
            );
            $identity = $this->client->identity($provider, $tokens, $config->clientId(), (string) $data['nonce']);

            if ($linking) {
                $user = wp_get_current_user();
                if (! $user->exists() || $user->ID !== (int) $data['user']) {
                    throw new OAuthException(esc_html(OAuthException::STATE), esc_html('Linking user changed.'));
                }
                $this->accounts->link($user, $identity);
                return $this->clearState($this->toProfile(['portier-social' => 'linked', 'provider' => $provider->id]));
            }

            $user = $this->accounts->resolve($identity, $config);
        } catch (OAuthException $exception) {
            return $this->clearState($this->fail($request, $exception->reason, $provider, $linking, $exception));
        }

        $result = $this->flow->afterFirstFactor(
            $user,
            (bool) $data['remember'],
            RedirectResolver::sanitizeRequested((string) $data['redirect']),
            (string) $request->cookie(TwoFactorService::DEVICE_COOKIE),
            'social:' . $provider->id
        );
        return $this->clearState($this->responder->afterAuth($request, $result));
    }

    /**
     * Disconnects a provider from the current user (POST from the profile, nonce-protected there).
     */
    public function unlink(WP_User $user, string $providerId): void
    {
        if (Providers::get($providerId) !== null) {
            $this->accounts->unlink($user, $providerId);
        }
    }

    public function config(Provider $provider): ProviderConfig
    {
        return ProviderConfig::for($provider, $this->responder->settings(), $this->crypto);
    }

    private function fail(Request $request, string $reason, ?Provider $provider, bool $linking, ?OAuthException $exception = null): Response
    {
        /**
         * Fires when a social login fails – for logging. `$details` may contain technical data
         * and must not be shown to visitors.
         *
         * @param string $reason   One of the OAuthException constants.
         * @param string $provider Provider id ('' if unknown).
         * @param string $details
         */
        do_action('portier_social_login_failed', $reason, $provider->id ?? '', $exception?->getMessage() ?? '');

        $args = array_filter(['provider' => $provider->id ?? '']);
        if ($linking) {
            return $this->toProfile(['portier-social' => 'error', 'reason' => $reason] + $args);
        }
        return $this->responder->redirect($request, $this->responder->route()->url('login', ['notice' => 'oauth-' . $reason] + $args));
    }

    /**
     * @param array<string, string> $args
     */
    private function toProfile(array $args): Response
    {
        return Response::redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('profile.php')) . '#portier-social', 303);
    }

    private function clearState(Response $response): Response
    {
        return $this->responder->withoutCookie($response, StateStore::COOKIE);
    }
}
