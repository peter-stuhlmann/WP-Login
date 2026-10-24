<?php

declare(strict_types=1);

namespace Portier\Http;

use Closure;

/**
 * Work that must not delay the answer to the visitor – because the delay would tell something.
 *
 * E-mails that are only sent for existing accounts (login link, password reset, "you already
 * have an account") would otherwise make the response measurably slower for them, and anybody
 * could find out which addresses are registered by timing the form.
 *
 * {@see AfterResponse::finish()} closes the connection and then runs the queued tasks where the
 * server allows it (PHP-FPM, LiteSpeed). Elsewhere, and if nobody calls it, they run on
 * `shutdown` – later in the request, but they always run.
 */
final class AfterResponse
{
    /** @var list<Closure(): void> */
    private static array $tasks = [];

    /**
     * @param Closure(): void $task
     */
    public static function queue(Closure $task): void
    {
        if (self::$tasks === []) {
            add_action('shutdown', [self::class, 'run'], 0);
        }
        self::$tasks[] = $task;
    }

    /**
     * Ends the response (the visitor gets it now) and runs the queued tasks.
     */
    public static function finish(): void
    {
        if (self::$tasks === []) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        self::run();
    }

    /**
     * Drops the queued tasks without running them (tests: every test is its own "request").
     */
    public static function discard(): void
    {
        self::$tasks = [];
    }

    /**
     * Runs the queued tasks (in order, each once).
     */
    public static function run(): void
    {
        while (self::$tasks !== []) {
            $task = array_shift(self::$tasks);
            $task();
        }
    }
}
