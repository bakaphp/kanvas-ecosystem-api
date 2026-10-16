<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CalendarableMapper;
use Tests\TestCase;

final class CalendarableMapperTest extends TestCase
{
    private function cutoff(): Carbon
    {
        return Carbon::parse('2026-08-21 00:00:00', 'UTC');
    }

    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 512,
            'type' => 'public-class',
            'name' => 'Sunset Canvas',
            'description' => 'A calm one.',
            'start' => '2026-09-15 01:00:00',
            'end' => '2026-09-15 04:00:00',
            'occupancy' => 24,
            'fundraiser' => 0,
            'rsvp' => false,
            'private' => false,
            'password' => null,
            'painting_id' => 77,
            'studio_id' => 3,
            'published_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ], $overrides);
    }

    private function map(CalendarableTypeEnum $morph, array $overrides = [], string $tz = 'America/Denver'): array
    {
        return CalendarableMapper::map($morph, $this->row($overrides), 3, $tz, $this->cutoff());
    }

    /** The version column is the source id, so the composite unique key needs no lookup. */
    public function testVersionIsKeyedOnTheSourceId(): void
    {
        $mapped = $this->map(CalendarableTypeEnum::LESSON, ['id' => 512]);

        $this->assertSame(512, $mapped['version']['version']);
        $this->assertSame('bc-lesson-v512', $mapped['version']['slug']);
    }

    public function testClassesSharingAPaintingAndStudioCollapseIntoOneEvent(): void
    {
        $first = $this->map(CalendarableTypeEnum::LESSON, ['id' => 1, 'painting_id' => 77]);
        $second = $this->map(CalendarableTypeEnum::LESSON, ['id' => 2, 'painting_id' => 77]);

        $this->assertSame($first['group_key'], $second['group_key']);
        $this->assertSame($first['event']['slug'], $second['event']['slug']);
        $this->assertNotSame($first['version']['slug'], $second['version']['slug']);
    }

    public function testDifferentStudiosNeverShareAnEvent(): void
    {
        $a = CalendarableMapper::map(CalendarableTypeEnum::LESSON, $this->row(), 1, 'America/Denver', $this->cutoff());
        $b = CalendarableMapper::map(CalendarableTypeEnum::LESSON, $this->row(), 3, 'America/Denver', $this->cutoff());

        $this->assertNotSame($a['group_key'], $b['group_key']);
        $this->assertNotSame($a['event']['slug'], $b['event']['slug']);
    }

    /**
     * 100% of workshops and 97.6% of private events have no painting in production, so the name
     * fallback is the main path — and every drop-in spelling has to land on one Event or a single
     * recurring session shatters into hundreds.
     */
    public function testDropInVariantsWithoutAPaintingStillCollapse(): void
    {
        $keys = [];

        foreach (['Walk in & Paint 1-8pm', 'Drop-In and Paint 1-8pm', 'Drop-N & Paint 1-8pm'] as $name) {
            $mapped = $this->map(CalendarableTypeEnum::WORKSHOP, ['name' => $name, 'painting_id' => null]);
            $keys[] = $mapped['group_key'];
        }

        $this->assertCount(1, array_unique($keys));
    }

    public function testPrivateEventsAreNeverGrouped(): void
    {
        $a = $this->map(CalendarableTypeEnum::EVENT, ['id' => 10, 'painting_id' => null]);
        $b = $this->map(CalendarableTypeEnum::EVENT, ['id' => 11, 'painting_id' => null]);

        $this->assertNotSame($a['group_key'], $b['group_key']);
        $this->assertNotSame($a['event']['slug'], $b['event']['slug']);
    }

    public function testCapacityLandsOnTheKeyKanvasReads(): void
    {
        $this->assertSame(24, $this->map(CalendarableTypeEnum::LESSON)['version']['metadata']['max_capacity']);
        $this->assertSame(0, $this->map(CalendarableTypeEnum::LESSON, ['occupancy' => null])['version']['metadata']['max_capacity']);
    }

    /** A private-event gate password must never be copied into Kanvas, only its existence. */
    public function testGatePasswordIsNeverCarriedOver(): void
    {
        $metadata = $this->map(CalendarableTypeEnum::EVENT, ['password' => 'hunter2'])['version']['metadata'];

        $this->assertTrue($metadata['has_password']);
        $this->assertNotContains('hunter2', $metadata, 'the plaintext password leaked into metadata');
    }

    public function testApprovalLadderMetadataOnlyExistsForPrivateEvents(): void
    {
        $event = $this->map(CalendarableTypeEnum::EVENT, ['approved_at' => '2026-01-05 00:00:00'])['version']['metadata'];
        $lesson = $this->map(CalendarableTypeEnum::LESSON)['version']['metadata'];

        $this->assertArrayHasKey('approved_at', $event);
        $this->assertArrayHasKey('organizer_email', $event);
        $this->assertArrayNotHasKey('approved_at', $lesson);
        $this->assertArrayNotHasKey('organizer_email', $lesson);
    }

    public function testTimezoneSemanticsTravelOnTheVersionMetadata(): void
    {
        $columbus = $this->map(CalendarableTypeEnum::LESSON, [], 'America/New_York')['version']['metadata'];

        $this->assertSame('corrected', $columbus['tz_semantics']);
        $this->assertSame('America/New_York', $columbus['tz_display']);
    }

    public function testMissingStartYieldsNoDateRow(): void
    {
        $mapped = $this->map(CalendarableTypeEnum::LESSON, ['start' => null, 'end' => null]);

        $this->assertNull($mapped['date']);
        $this->assertNull($mapped['instant']);
        $this->assertNull($mapped['version']['start_at']);
    }

    /** `lessons.name` is nullable in production, and slug/name are NOT NULL on the Kanvas side. */
    public function testNullNameStillProducesAUsableSlugAndName(): void
    {
        $mapped = $this->map(CalendarableTypeEnum::LESSON, ['name' => null, 'painting_id' => null]);

        $this->assertNotSame('', $mapped['event']['slug']);
        $this->assertNotSame('', $mapped['event']['name']);
        $this->assertStringContainsString('512', $mapped['event']['name']);
    }

    public function testSoftDeletedSourceRowIsMarkedDeletedOnBothLevels(): void
    {
        $mapped = $this->map(CalendarableTypeEnum::LESSON, ['deleted_at' => '2026-02-01 00:00:00']);

        $this->assertTrue($mapped['event']['is_deleted']);
        $this->assertTrue($mapped['version']['is_deleted']);
    }

    public function testEventSlugStaysWithinTheColumnLimit(): void
    {
        $mapped = $this->map(CalendarableTypeEnum::WORKSHOP, [
            'name' => str_repeat('Sunset Canvas Extravaganza ', 40),
            'painting_id' => null,
        ]);

        $this->assertLessThanOrEqual(255, strlen($mapped['event']['slug']));
    }
}
