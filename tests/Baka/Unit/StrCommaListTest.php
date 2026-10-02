<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrCommaListTest extends TestCase
{
    public function testSplitsAndTrimsEachPart(): void
    {
        $this->assertSame(['CONFIRMADO', 'CONFIRMADO PLAN'], Str::commaList(' CONFIRMADO ,CONFIRMADO PLAN '));
    }

    public function testDropsBlankPartsAndKeepsAListShape(): void
    {
        $this->assertSame(['a', 'b'], Str::commaList('a, , ,b,'));
    }

    public function testNothingIsAnEmptyList(): void
    {
        $this->assertSame([], Str::commaList(null));
        $this->assertSame([], Str::commaList('  '));
    }

    /**
     * Unlike a falsy filter, "0" is a value — a version id or an amount can legitimately be zero.
     */
    public function testZeroIsKept(): void
    {
        $this->assertSame(['0', '1'], Str::commaList('0,1'));
    }
}
