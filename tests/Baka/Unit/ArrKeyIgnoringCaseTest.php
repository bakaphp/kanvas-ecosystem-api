<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Support\Arr;
use Tests\TestCase;

final class ArrKeyIgnoringCaseTest extends TestCase
{
    public function testAnExactKeyWinsOverACaseInsensitiveSibling(): void
    {
        $this->assertSame('Case', Arr::keyIgnoringCase(['case' => 1, 'Case' => 2], 'Case'));
    }

    public function testAKeyIsFoundRegardlessOfCasing(): void
    {
        $this->assertSame('Best Time To Contact', Arr::keyIgnoringCase(['Best Time To Contact' => 'am'], 'best time to contact'));
    }

    public function testANullValueStillCountsAsPresent(): void
    {
        $this->assertSame('Email', Arr::keyIgnoringCase(['Email' => null], 'email'));
    }

    public function testAnIntegerKeyIsReachedThroughTheReturnedKey(): void
    {
        $list = ['a'];

        $this->assertSame('a', $list[Arr::keyIgnoringCase($list, '0')]);
    }

    public function testAMissingKeyIsNull(): void
    {
        $this->assertNull(Arr::keyIgnoringCase(['email' => 'x'], 'phone'));
    }
}
