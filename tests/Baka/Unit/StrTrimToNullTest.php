<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrTrimToNullTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('Head of Sales', Str::trimToNull("  Head of Sales\n"));
    }

    public function testBlankValuesBecomeNull(): void
    {
        $this->assertNull(Str::trimToNull(null));
        $this->assertNull(Str::trimToNull(''));
        $this->assertNull(Str::trimToNull("   \t\n "));
    }

    /**
     * The whole reason this exists: `trim($x) ?: null` is falsy-based, so an employee number or a
     * quantity of "0" silently disappears. Every caller migrated to this helper depends on it.
     */
    public function testZeroIsAValueNotAnAbsence(): void
    {
        $this->assertSame('0', Str::trimToNull('0'));
        $this->assertSame('0', Str::trimToNull('  0  '));
        $this->assertNull(trim('0') ?: null, 'the idiom this replaces is what loses "0"');
    }

    public function testInnerWhitespaceIsLeftAlone(): void
    {
        $this->assertSame('Ana  Perez', Str::trimToNull('  Ana  Perez  '));
    }
}
