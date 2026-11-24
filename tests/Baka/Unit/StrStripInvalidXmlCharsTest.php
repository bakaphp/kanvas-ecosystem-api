<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrStripInvalidXmlCharsTest extends TestCase
{
    public function testDropsControlCharacters(): void
    {
        $this->assertSame('badchar', Str::stripInvalidXmlChars("bad\x0B\x0C\x1B\x00char"));
    }

    public function testKeepsWhitespaceAndMultibyteText(): void
    {
        $this->assertSame("Año\tuno\ndos\r 😀", Str::stripInvalidXmlChars("Año\tuno\ndos\r 😀"));
    }

    public function testInvalidUtf8BecomesValidText(): void
    {
        $clean = Str::stripInvalidXmlChars("inv\xC3alid");

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertStringStartsWith('inv', $clean);
        $this->assertStringEndsWith('alid', $clean);
    }
}
