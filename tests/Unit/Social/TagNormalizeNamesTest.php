<?php

declare(strict_types=1);

namespace Tests\Unit\Social;

use Kanvas\Social\Tags\Models\Tag;
use Tests\TestCaseUnit;

class TagNormalizeNamesTest extends TestCaseUnit
{
    public function testNormalizesGraphQlTagInputShape(): void
    {
        $this->assertSame(
            ['summer', 'sale'],
            Tag::normalizeNames([['name' => 'summer'], ['name' => 'sale']])
        );
    }

    public function testNormalizesPlainNameList(): void
    {
        $this->assertSame(['summer', 'sale'], Tag::normalizeNames(['summer', 'sale']));
    }

    public function testNormalizesCsvCellAndTrims(): void
    {
        $this->assertSame(['summer', 'sale'], Tag::normalizeNames('summer, sale '));
    }

    public function testDeduplicatesPreservingFirstSeenOrder(): void
    {
        $this->assertSame(['summer', 'sale'], Tag::normalizeNames(['summer', 'sale', 'summer']));
    }

    public function testDropsEmptyAndWhitespaceOnlyEntries(): void
    {
        $this->assertSame(['summer'], Tag::normalizeNames(['summer', '', '   ', null]));
    }

    public function testReturnsEmptyArrayForEmptyOrNonListInput(): void
    {
        $this->assertSame([], Tag::normalizeNames([]));
        $this->assertSame([], Tag::normalizeNames(''));
        $this->assertSame([], Tag::normalizeNames(null));
    }

    public function testKeepsIntegerTagIdsSinceAddTagAcceptsThem(): void
    {
        $this->assertSame([42], Tag::normalizeNames([42]));
    }
}
