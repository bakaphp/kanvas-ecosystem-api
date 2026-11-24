<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use PHPUnit\Framework\TestCase;

final class CalendarableKeyTest extends TestCase
{
    public function testParsesAValidKey(): void
    {
        $this->assertSame([CalendarableTypeEnum::WORKSHOP, 42], CalendarableTypeEnum::parseKey('workshop:42'));
    }

    public function testRejectsUnknownMorphMissingOrNonPositiveIds(): void
    {
        $this->assertNull(CalendarableTypeEnum::parseKey('painting:1'));
        $this->assertNull(CalendarableTypeEnum::parseKey('lesson'));
        $this->assertNull(CalendarableTypeEnum::parseKey('lesson:abc'));
        $this->assertNull(CalendarableTypeEnum::parseKey('lesson:0'));
        $this->assertNull(CalendarableTypeEnum::parseKey(''));
    }

    public function testGroupsValidKeysByMorphAndDropsTheRest(): void
    {
        $this->assertSame(
            ['lesson' => [1, 3], 'event' => [2]],
            CalendarableTypeEnum::idsByMorph(['lesson:1', 'event:2', 'bogus:9', 'lesson:3', 'event:x'])
        );
    }
}
