<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Arr;
use Tests\TestCase;

final class ArrFromSettingListTest extends TestCase
{
    public function testReadsEveryShapeASettingIsStoredIn(): void
    {
        $this->assertSame(['a.com', 'b.com'], Arr::fromSettingList(['a.com', ' b.com ']));
        $this->assertSame(['a.com', 'b.com'], Arr::fromSettingList('["a.com","b.com"]'));
        $this->assertSame(['a.com', 'b.com'], Arr::fromSettingList('a.com, ,b.com,'));
    }

    public function testAnythingElseIsAnEmptyList(): void
    {
        $this->assertSame([], Arr::fromSettingList(null));
        $this->assertSame([], Arr::fromSettingList(''));
        $this->assertSame([], Arr::fromSettingList(5));
    }
}
