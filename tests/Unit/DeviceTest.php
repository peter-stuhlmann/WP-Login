<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\Frontend\Device;

/**
 * @covers \Portier\Frontend\Device
 */
final class DeviceTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function agents(): array
    {
        return [
            'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 'Chrome', 'Windows', false],
            'Edge before Chrome' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge', 'Windows', false],
            'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', true],
            'Firefox on Android' => ['Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0', 'Firefox', 'Android', true],
            'Safari on macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'Safari', 'macOS', false],
            'unknown' => ['curl/8.0', '', '', false],
        ];
    }

    /**
     * @dataProvider agents
     */
    public function test_describes_browser_and_system(string $agent, string $browser, string $os, bool $mobile): void
    {
        $this->assertSame(['browser' => $browser, 'os' => $os, 'mobile' => $mobile], Device::describe($agent));
    }
}
