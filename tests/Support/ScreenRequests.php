<?php

declare(strict_types=1);

namespace Portier\Tests\Support;

use Portier\Http\AfterResponse;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\Plugin;
use Portier\Security\FormToken;
use WP_Session_Tokens;

/**
 * Helpers to drive the Portier screens like a browser would, without a browser.
 */
trait ScreenRequests
{
    protected static string $csrfCookie = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @var array<string, string> Cookies set by previous responses (like a browser jar). */
    protected array $jar = [];

    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $cookies
     */
    protected function send(string $method, array $query = [], array $post = [], array $cookies = [], bool $fragment = false): Response
    {
        $request = new Request(
            $method,
            $query,
            $post,
            ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'] + ($fragment ? ['HTTP_X_PORTIER_REQUEST' => 'fragment'] : []),
            $cookies + $this->jar + [FormToken::COOKIE => self::$csrfCookie]
        );
        $response = Plugin::instance()->controller($request)->handle($request);
        // What runs once the response is sent (e-mails), like Plugin::handleRequest().
        AfterResponse::run();
        foreach ($response->cookies() as $cookie) {
            if ($cookie['expires'] !== 0 && $cookie['expires'] < time()) {
                unset($this->jar[$cookie['name']]);
            } else {
                $this->jar[$cookie['name']] = $cookie['value'];
            }
        }
        return $response;
    }

    /**
     * Logs a user in like a browser does: a session (started $ago seconds ago) and its cookie.
     */
    protected function logInSession(int $userId, int $ago = 0): void
    {
        $sessions = WP_Session_Tokens::get_instance($userId);
        $token = $sessions->create(time() + DAY_IN_SECONDS);
        $session = (array) $sessions->get($token);
        $session['login'] = time() - $ago;
        $sessions->update($token, $session);
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($userId, time() + DAY_IN_SECONDS, 'logged_in', $token);
        wp_set_current_user($userId);
    }

    protected function forgetSession(): void
    {
        unset($_COOKIE[LOGGED_IN_COOKIE]);
    }

    /**
     * Valid form token for an action.
     */
    protected function csrf(string $action): string
    {
        return (new FormToken(wp_salt('nonce'), self::$csrfCookie))->create($action);
    }

    /**
     * Posts a form of the given action with a valid token.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $query
     */
    protected function submit(string $action, array $fields, array $query = []): Response
    {
        return $this->send('POST', ($action === 'login' ? [] : ['action' => $action]) + $query, $fields + ['portier_token' => $this->csrf($action)]);
    }
}
