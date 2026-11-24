<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrNormalizeTaxIdTest extends TestCase
{
    public function testSeparatedNumbersBecomeDigits(): void
    {
        $this->assertSame('131794321', Str::normalizeTaxId(' 131-79432-1 '));
        $this->assertSame('123456789', Str::normalizeTaxId('12.345 678-9'));
        $this->assertSame('131794321', Str::normalizeTaxId(131794321));
    }

    public function testValuesWithLettersKeepTheirForm(): void
    {
        $this->assertSame('B12345678', Str::normalizeTaxId('  B12345678 '));
        $this->assertSame('XAXX-010101-000', Str::normalizeTaxId('XAXX-010101-000'));
    }

    public function testBlankIsNull(): void
    {
        $this->assertNull(Str::normalizeTaxId(null));
        $this->assertNull(Str::normalizeTaxId('   '));
        $this->assertNull(Str::normalizeTaxId(' - . '));
        $this->assertNull(Str::normalizeTaxId('000-00000-0'));
        $this->assertSame('001234567', Str::normalizeTaxId('001234567'));
    }
}
