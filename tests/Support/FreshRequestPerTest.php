<?php

declare(strict_types=1);

namespace Portier\Tests\Support;

use PHPUnit\Runner\AfterTestHook;
use Portier\Http\AfterResponse;

/**
 * Every test is its own "request": work queued for after the response (e-mails) must not leak
 * into the next test, whose database no longer has the data it refers to.
 */
final class FreshRequestPerTest implements AfterTestHook
{
    public function executeAfterTest(string $test, float $time): void
    {
        AfterResponse::discard();
    }
}
