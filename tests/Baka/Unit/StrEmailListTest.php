<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrEmailListTest extends TestCase
{
    public function testSplitsOnCommasSemicolonsAndWhitespace(): void
    {
        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            Str::emailList('a@example.com, b@example.com;c@example.com ')
        );
    }

    public function testDropsAnythingThatIsNotAnAddress(): void
    {
        $this->assertSame(['ops@example.com'], Str::emailList('ops@example.com, not-an-email, @nope'));
    }

    public function testAcceptsAnArrayAsAnAppSettingMayStoreOne(): void
    {
        $this->assertSame(['a@example.com'], Str::emailList(['a@example.com', '', null, 42]));
    }

    public function testNothingIsAnEmptyList(): void
    {
        $this->assertSame([], Str::emailList(null));
        $this->assertSame([], Str::emailList('   '));
        $this->assertSame([], Str::emailList([]));
    }
}
