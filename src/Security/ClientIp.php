<?php

declare(strict_types=1);

namespace Portier\Security;

/**
 * Determines the client IP for rate limiting.
 *
 * By default only REMOTE_ADDR is trusted: proxy headers can be forged by anyone and would let
 * an attacker pick a fresh "IP" for every request. Sites behind a reverse proxy or CDN can opt
 * into exactly one trusted header in the settings.
 */
final class ClientIp
{
    private const FORWARDED_FOR = 'HTTP_X_FORWARDED_FOR';

    /**
     * @param array<string, mixed> $server Usually $_SERVER.
     */
    public static function resolve(array $server, string $trustedHeader = ''): string
    {
        if ($trustedHeader !== '' && ! empty($server[$trustedHeader])) {
            $ip = $trustedHeader === self::FORWARDED_FOR
                ? self::fromForwardedFor((string) $server[$trustedHeader])
                : self::valid(trim((string) $server[$trustedHeader]));
            if ($ip !== null) {
                return $ip;
            }
        }

        $remote = isset($server['REMOTE_ADDR']) ? trim((string) $server['REMOTE_ADDR']) : '';
        return self::valid($remote) ?? '0.0.0.0';
    }

    /**
     * X-Forwarded-For is a chain "client, proxy1, proxy2" to which every proxy appends the address
     * it received the request from. Only the end of the chain comes from our own proxies – the
     * beginning is whatever the client sent. So the chain is read from the right: hops inside a
     * private network (load balancers) are skipped, the first public address is the client.
     * If every address is private (e.g. an intranet), the last one is used. (Visitors from inside
     * that private network could therefore still choose their address – not visitors from the
     * internet, whose address is appended by the proxy.)
     */
    private static function fromForwardedFor(string $header): ?string
    {
        $chain = array_values(array_filter(array_map(static fn (string $ip): ?string => self::valid(trim($ip)), explode(',', $header))));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (filter_var($chain[$i], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return $chain[$i];
            }
        }
        return $chain[count($chain) - 1] ?? null;
    }

    private static function valid(string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
