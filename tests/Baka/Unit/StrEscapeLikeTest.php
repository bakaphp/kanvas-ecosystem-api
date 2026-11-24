<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Str;
use Tests\TestCase;

final class StrEscapeLikeTest extends TestCase
{
    public function testWildcardsAndTheEscapeCharacterAreEscaped(): void
    {
        $this->assertSame('my\_app', Str::escapeLike('my_app'));
        $this->assertSame('100\%', Str::escapeLike('100%'));
        $this->assertSame('a\\\\b', Str::escapeLike('a\\b'));
    }

    public function testOrdinaryTextIsLeftAlone(): void
    {
        $this->assertSame('example-app', Str::escapeLike('example-app'));
        $this->assertSame('', Str::escapeLike(''));
    }
}
