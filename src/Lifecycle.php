<?php

declare(strict_types=1);

namespace Portier;

/**
 * Activation and deactivation hooks.
 *
 * Rewrite rules are not flushed here directly: the login route is registered on `init`,
 * which has not run yet during activation. {@see Routing\LoginRoute::maybeFlush()} compares a
 * signature of the current route configuration and flushes lazily on the next request. This also
 * covers network activation, where every site flushes itself on its first request.
 */
final class Lifecycle
{
    public static function activate(): void
    {
        delete_option(Routing\LoginRoute::SIGNATURE_OPTION);
        (new Audit\AuditSubscriber())->log('plugin.activated', static fn (): array => [__('Portier activated', 'portier')]);
    }

    public static function deactivate(): void
    {
        (new Audit\AuditSubscriber())->log('plugin.deactivated', static fn (): array => [__('Portier deactivated', 'portier')]);
        // Forces WordPress to rebuild the rules without our route on the next request.
        delete_option('rewrite_rules');
        delete_option(Routing\LoginRoute::SIGNATURE_OPTION);
    }
}
