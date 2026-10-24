<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Auth\AuthResult;
use Portier\Auth\PendingLogin;
use Portier\Auth\RegistrationService;
use Portier\Design\Design;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\Routing\LoginRoute;
use Portier\Security\FormToken;
use Portier\Security\SecurityHeaders;
use Portier\Settings\Settings;

/**
 * Everything the screen classes share: rendering a page (or a JSON fragment for the
 * enhancement script), redirects, CSRF checks and cookies scoped to the login page.
 */
final class Responder
{
    /** Registration bots fill hidden fields; humans never see this one. */
    public const HONEYPOT_FIELD = 'portier_website';

    public function __construct(
        private readonly LoginRoute $route,
        private readonly Settings $settings,
        private readonly View $view,
        private readonly FormToken $token,
    ) {
    }

    public function route(): LoginRoute
    {
        return $this->route;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function token(): FormToken
    {
        return $this->token;
    }

    /**
     * Renders a screen as a full page or, for the enhancement script, as a JSON fragment.
     *
     * @param string               $action   Portier action of the screen (for URLs and the form token).
     * @param array<string, mixed> $data
     * @param array<string, scalar> $selfArgs Extra query arguments for the form action URL.
     */
    public function page(Request $request, string $action, string $template, string $title, array $data, int $status = 200, array $selfArgs = []): Response
    {
        $data += [
            'title' => $title,
            'errors' => [],
            'notice' => null,
            'values' => [],
            'urls' => $this->urls($request, $action, $selfArgs),
            'token' => $this->token->create($action),
            'honeypot' => self::HONEYPOT_FIELD,
            'back' => '', // URL for the "Back" pill in the header (follow-up screens).
        ];
        $content = $this->view->render('views/' . $template, $data);
        $documentTitle = sprintf('%s ‹ %s', $title, get_bloginfo('name'));

        $languages = $this->languages($request, $action);

        if ($request->wantsFragment()) {
            return Response::json([
                'html' => $content,
                'title' => $documentTitle,
                'url' => $data['urls']['self'],
                // Errors first; otherwise the screen's own target (an element id) or its heading.
                'focus' => $data['errors'] !== [] ? 'errors' : (string) ($data['focus'] ?? 'heading'),
                'back' => (string) $data['back'],
                // Language of the content and a switcher whose links lead to this screen.
                'lang' => str_replace('_', '-', get_bloginfo('language')),
                'switcher' => $this->view->render('partials/language-switcher', ['languages' => $languages]),
            ], $status);
        }

        return Response::html($this->view->render('layout', [
            'design' => Design::fromSettings($this->settings),
            'preview' => $this->settings->isPreview(),
            'content' => $content,
            'document_title' => $documentTitle,
            'screen' => $template,
            'urls' => $data['urls'],
            'site' => $this->site(),
            'languages' => $languages,
            'legal' => $this->legalLinks(),
            'back' => (string) $data['back'],
        ]), $status);
    }

    /**
     * Fragment requests get JSON; Portier URLs are then loaded in place by the script.
     */
    public function redirect(Request $request, string $url): Response
    {
        if ($request->wantsFragment()) {
            return Response::json(['redirect' => $url, 'internal' => $this->route->owns($url)]);
        }
        return Response::redirect($url);
    }

    /**
     * Back to the login screen with a status message (see {@see Controller::loginNotice()}).
     */
    public function toLogin(Request $request, string $notice): Response
    {
        return $this->redirect($request, $this->route->url('login', ['notice' => $notice]));
    }

    /**
     * Continues after a successful first factor: straight to the target, or – with a pending
     * login – to the 2FA step.
     */
    public function afterAuth(Request $request, AuthResult $result): Response
    {
        if (isset($result->data['next'], $result->data['pending'])) {
            return $this->withCookie(
                $this->redirect($request, $this->route->url((string) $result->data['next'])),
                PendingLogin::COOKIE,
                (string) $result->data['pending']
            );
        }
        return $this->redirect($request, (string) $result->redirect);
    }

    /**
     * @return string|null Error message, or null when the form token is valid.
     */
    public function verifyToken(Request $request, string $action): ?string
    {
        if (! $this->token->hasCookie()) {
            return __('Your browser blocks cookies. Please allow cookies for this site to log in.', 'portier');
        }
        if (! $this->token->verify($action, $request->input('portier_token'))) {
            return __('For your security the form has expired. Please submit it again.', 'portier');
        }
        return null;
    }

    /**
     * HttpOnly cookie limited to the login page (session cookie unless $expires is given).
     */
    public function withCookie(Response $response, string $name, string $value, int $expires = 0): Response
    {
        return $response->withCookie($name, $value, $expires, $this->route->cookiePath());
    }

    public function withoutCookie(Response $response, string $name): Response
    {
        return $response->withCookie($name, ' ', time() - YEAR_IN_SECONDS, $this->route->cookiePath());
    }

    public function finalize(Response $response, bool $framable = false): Response
    {
        foreach (SecurityHeaders::forLoginPage($framable) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $value = $this->token->cookieValue();
        return $this->token->needsCookie() ? $this->withCookie($response, FormToken::COOKIE, $value) : $response;
    }

    /**
     * @param array<string, scalar> $selfArgs
     * @return array<string, string>
     */
    private function urls(Request $request, string $action, array $selfArgs): array
    {
        $keep = array_filter(['redirect_to' => $request->query('redirect_to')]);
        return [
            'self' => $this->route->url($action, ($action === 'login' ? $keep : []) + $selfArgs),
            'login' => $this->route->url('login', $keep),
            'lostpassword' => $this->route->url('lostpassword'),
            'register' => RegistrationService::isOpen() ? $this->route->url('register') : '',
            'magiclink' => $this->settings->bool('magic_link') ? $this->route->url('magiclink', $keep) : '',
            'recovery' => $this->route->url('recovery'),
            'twofactor' => $this->route->url('2fa'),
            'home' => home_url('/'),
        ];
    }

    /**
     * @return array{name: string, url: string, icon: string}
     */
    private function site(): array
    {
        return [
            'name' => (string) get_bloginfo('name'),
            'url' => home_url('/'),
            'icon' => (string) get_site_icon_url(96),
        ];
    }

    /**
     * Language switcher entries (empty when disabled or only one language is installed).
     *
     * @return list<array{locale: string, label: string, short: string, url: string, current: bool, lang: string}>
     */
    private function languages(Request $request, string $action): array
    {
        if (! $this->settings->bool('language_switcher')) {
            return [];
        }
        $available = Language::available();
        if (count($available) < 2) {
            return [];
        }
        // After switch_to_locale() only get_locale() reflects the switched language.
        $current = is_locale_switched() ? get_locale() : determine_locale();
        $query = array_filter($request->query, 'is_scalar');
        unset($query['wp_lang'], $query['action'], $query[LoginRoute::QUERY_VAR], $query['key'], $query['login'], $query['token']);
        $entries = [];
        foreach ($available as $locale => $label) {
            $entries[] = [
                'locale' => $locale,
                'label' => $label,
                'short' => Language::shortLabel($locale),
                'url' => $this->route->url($action, ['wp_lang' => $locale] + $query),
                'current' => $locale === $current,
                'lang' => Language::bcp47($locale),
            ];
        }
        return $entries;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function legalLinks(): array
    {
        $links = [];
        $privacy = get_privacy_policy_url();
        if ($privacy !== '') {
            $links[] = ['label' => __('Privacy', 'portier'), 'url' => $privacy];
        }
        foreach (['imprint_page' => __('Imprint', 'portier'), 'accessibility_page' => __('Accessibility', 'portier')] as $setting => $label) {
            $pageId = $this->settings->int($setting);
            if ($pageId > 0 && get_post_status($pageId) === 'publish') {
                $links[] = ['label' => $label, 'url' => (string) get_permalink($pageId)];
            }
        }
        return $links;
    }
}
