<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrLowerTrimTest extends TestCase
{
    public function testTrimsAndLowercases(): void
    {
        $this->assertSame('awaiting_team_response', Str::lowerTrim("  Awaiting_Team_Response\n"));
    }

    public function testAbsentOrBlankBecomesEmptyString(): void
    {
        $this->assertSame('', Str::lowerTrim(null));
        $this->assertSame('', Str::lowerTrim("   \t\n "));
    }

    public function testInnerWhitespaceIsLeftAlone(): void
    {
        $this->assertSame('used  truck', Str::lowerTrim('  Used  Truck  '));
    }

    /**
     * Why it is multibyte: the inline `strtolower(trim())` it replaces leaves accented capitals alone, so
     * a comparison between "ÚNICO" and "único" silently never matched.
     */
    public function testLowercasesAccentedCapitals(): void
    {
        $this->assertSame('único', Str::lowerTrim(' ÚNICO '));
        $this->assertNotSame('único', strtolower('ÚNICO'), 'the idiom this replaces does not lowercase Ú');
    }
}
