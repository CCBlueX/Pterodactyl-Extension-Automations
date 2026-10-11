<?php

declare(strict_types=1);

namespace Automations\Tests\Unit;

use Automations\Support\Targets;
use PHPUnit\Framework\TestCase;

final class TargetsTest extends TestCase
{
    public function test_private_and_plain_http_targets_are_refused(): void
    {
        $this->assertNotNull(Targets::problem('http://example.com/hook', false));
        $this->assertNotNull(Targets::problem('https://127.0.0.1/hook', false));
        $this->assertNotNull(Targets::problem('https://[::1]/hook', false));
        $this->assertNotNull(Targets::problem('https://10.0.0.5/api/push/abc', false));
        $this->assertNull(Targets::problem('https://1.1.1.1/hook', false));
        $this->assertNull(Targets::problem('http://10.0.0.5:3001/api/push/abc', true));
        $this->assertNotNull(Targets::problem('ftp://10.0.0.5/', true));
    }
}
