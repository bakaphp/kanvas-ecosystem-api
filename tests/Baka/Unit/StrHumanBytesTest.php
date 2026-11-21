<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrHumanBytesTest extends TestCase
{
    public function testBytesAreRenderedInTheLargestUnit(): void
    {
        $this->assertSame('512B', Str::humanBytes(512));
        $this->assertSame('1.5K', Str::humanBytes(1536));
        $this->assertSame('8.45G', Str::humanBytes(9_073_118_208, 2));
    }

    public function testAnUnknownSizeIsAQuestionMark(): void
    {
        $this->assertSame('?', Str::humanBytes(0));
        $this->assertSame('?', Str::humanBytes(-1));
    }
}
