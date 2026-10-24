<?php

/**
 * Local OpenID Connect provider for the end-to-end tests – a real browser round trip without
 * Google & co. Answers only requests from this machine. Never part of a release.
 *
 * Routes (PATH_INFO): /.well-known/openid-configuration, /authorize (consent page),
 * /approve (POST), /token (POST, back channel), /jwks.
 *
 * @package Portier
 */

declare(strict_types=1);

if (! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}

const PORTIER_IDP_CLIENT_ID = 'e2e-client';
const PORTIER_IDP_CLIENT_SECRET = 'e2e-secret';

$portier_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
$portier_route = $_SERVER['PATH_INFO'] ?? '/';
$portier_store = sys_get_temp_dir() . '/portier-e2e-idp-codes.json';

/**
 * @param array<string, mixed> $data
 */
function portier_idp_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function portier_idp_b64(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function portier_idp_key(): OpenSSLAsymmetricKey
{
    $key = openssl_pkey_get_private((string) file_get_contents(dirname(__DIR__, 2) . '/Support/fixtures/idp-key.pem'));
    if ($key === false) {
        portier_idp_json(['error' => 'no key'], 500);
    }
    return $key;
}

/**
 * @return array<string, array<string, mixed>>
 */
function portier_idp_codes(string $file): array
{
    $codes = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    return is_array($codes) ? $codes : [];
}

switch ($portier_route) {
    case '/.well-known/openid-configuration':
        portier_idp_json([
            'issuer' => $portier_base,
            'authorization_endpoint' => $portier_base . '/authorize',
            'token_endpoint' => $portier_base . '/token',
            'jwks_uri' => $portier_base . '/jwks',
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]);
        // No break: portier_idp_json() exits.

    case '/jwks':
        $portier_details = openssl_pkey_get_details(portier_idp_key());
        portier_idp_json(['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'e2e',
            'n' => portier_idp_b64($portier_details['rsa']['n']),
            'e' => portier_idp_b64($portier_details['rsa']['e']),
        ]]]);
        // No break.

    case '/authorize':
        $portier_query = $_GET;
        header('Content-Type: text/html; charset=utf-8');
        $portier_fields = '';
        foreach (['redirect_uri', 'state', 'nonce', 'code_challenge', 'client_id'] as $portier_field) {
            $portier_fields .= sprintf('<input type="hidden" name="%1$s" value="%2$s">', $portier_field, htmlspecialchars((string) ($portier_query[$portier_field] ?? ''), ENT_QUOTES));
        }
        $portier_cancel = htmlspecialchars((string) ($portier_query['redirect_uri'] ?? '') . '?' . http_build_query(['error' => 'access_denied', 'state' => (string) ($portier_query['state'] ?? '')]), ENT_QUOTES);
        echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Test IdP</title></head>
<body>
<main>
<h1>Test IdP</h1>
<form method="post" action="{$portier_base}/approve">
    {$portier_fields}
    <label>Email <input name="email" type="email" required></label>
    <label><input name="verified" type="checkbox" value="1" checked> Email verified</label>
    <label>Name <input name="name" value="E2E Person"></label>
    <button type="submit">Allow</button>
</form>
<a href="{$portier_cancel}">Cancel</a>
</main>
</body></html>
HTML;
        exit;

    case '/approve':
        $portier_code = bin2hex(random_bytes(12));
        $portier_codes = portier_idp_codes($portier_store);
        $portier_codes[$portier_code] = [
            'email' => (string) ($_POST['email'] ?? ''),
            'verified' => ! empty($_POST['verified']),
            'name' => (string) ($_POST['name'] ?? ''),
            'nonce' => (string) ($_POST['nonce'] ?? ''),
            'challenge' => (string) ($_POST['code_challenge'] ?? ''),
            'redirect_uri' => (string) ($_POST['redirect_uri'] ?? ''),
            'expires' => time() + 60,
        ];
        file_put_contents($portier_store, json_encode($portier_codes), LOCK_EX);
        header('Location: ' . $portier_codes[$portier_code]['redirect_uri'] . '?' . http_build_query(['code' => $portier_code, 'state' => (string) ($_POST['state'] ?? '')]), true, 302);
        exit;

    case '/token':
        $portier_codes = portier_idp_codes($portier_store);
        $portier_code = (string) ($_POST['code'] ?? '');
        $portier_grant = $portier_codes[$portier_code] ?? null;
        unset($portier_codes[$portier_code]);
        file_put_contents($portier_store, json_encode($portier_codes), LOCK_EX);
        $portier_pkce = portier_idp_b64(hash('sha256', (string) ($_POST['code_verifier'] ?? ''), true));
        // Like real providers: the client is checked before the code ("Test connection").
        if (($_POST['client_id'] ?? '') !== PORTIER_IDP_CLIENT_ID || ($_POST['client_secret'] ?? '') !== PORTIER_IDP_CLIENT_SECRET) {
            portier_idp_json(['error' => 'invalid_client'], 401);
        }
        if (
            $portier_grant === null || $portier_grant['expires'] < time()
            || ($_POST['client_id'] ?? '') !== PORTIER_IDP_CLIENT_ID || ($_POST['client_secret'] ?? '') !== PORTIER_IDP_CLIENT_SECRET
            || ($_POST['redirect_uri'] ?? '') !== $portier_grant['redirect_uri']
            || ($portier_grant['challenge'] !== '' && $portier_pkce !== $portier_grant['challenge'])
        ) {
            portier_idp_json(['error' => 'invalid_grant'], 400);
        }
        $portier_claims = [
            'iss' => $portier_base,
            'aud' => PORTIER_IDP_CLIENT_ID,
            'sub' => 'e2e-' . substr(sha1($portier_grant['email']), 0, 16),
            'email' => $portier_grant['email'],
            'email_verified' => $portier_grant['verified'],
            'name' => $portier_grant['name'],
            'nonce' => $portier_grant['nonce'],
            'iat' => time(),
            'exp' => time() + 300,
        ];
        $portier_input = portier_idp_b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'e2e'])) . '.' . portier_idp_b64((string) json_encode($portier_claims));
        openssl_sign($portier_input, $portier_signature, portier_idp_key(), OPENSSL_ALGO_SHA256);
        portier_idp_json(['access_token' => 'e2e-' . $portier_code, 'token_type' => 'Bearer', 'id_token' => $portier_input . '.' . portier_idp_b64((string) $portier_signature)]);
        // No break.

    default:
        portier_idp_json(['error' => 'not_found'], 404);
}
