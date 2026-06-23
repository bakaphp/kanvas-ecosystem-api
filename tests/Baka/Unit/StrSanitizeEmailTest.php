<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCaseUnit;

final class StrSanitizeEmailTest extends TestCaseUnit
{
    public function testReplacesAtAndDot(): void
    {
        $this->assertSame('jerly-at-mctekk-dot-com', Str::sanitizeEmail('jerly@mctekk.com'));
    }

    public function testCollapsesPlusAddressingSoPusherChannelNameStaysValid(): void
    {
        $this->assertSame('jerly-103-at-mctekk-dot-com', Str::sanitizeEmail('jerly+103@mctekk.com'));
    }

    public function testCollapsesOtherUnsafeLocalPartCharacters(): void
    {
        $this->assertSame('a-b-c-at-mctekk-dot-com', Str::sanitizeEmail("a'b%c@mctekk.com"));
    }

    public function testResultOnlyContainsPusherSafeCharacters(): void
    {
        $sanitized = Str::sanitizeEmail('jerly+103@mctekk.com');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_=@,.;-]+$/', $sanitized);
    }
}
