<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\EventStatusEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\EventStatusMapper;
use Tests\TestCase;

final class EventStatusMapperTest extends TestCase
{
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'deleted_at' => null,
            'published_at' => null,
            'approved_at' => null,
            'deposit_requested_at' => null,
            'deposit_paid_at' => null,
            'finalized_at' => null,
            'end' => null,
        ], $overrides);
    }

    public function testPrivateEventLadderResolvesInPrecedenceOrder(): void
    {
        $cases = [
            EventStatusEnum::FINALIZED->value => ['finalized_at' => '2026-01-01 00:00:00', 'deposit_paid_at' => '2025-12-01 00:00:00', 'approved_at' => '2025-11-01 00:00:00'],
            EventStatusEnum::DEPOSIT_PAID->value => ['deposit_paid_at' => '2025-12-01 00:00:00', 'deposit_requested_at' => '2025-11-15 00:00:00'],
            EventStatusEnum::DEPOSIT_REQUESTED->value => ['deposit_requested_at' => '2025-11-15 00:00:00', 'approved_at' => '2025-11-01 00:00:00'],
            EventStatusEnum::APPROVED->value => ['approved_at' => '2025-11-01 00:00:00'],
            EventStatusEnum::PENDING->value => [],
        ];

        foreach ($cases as $expected => $overrides) {
            $this->assertSame(
                $expected,
                EventStatusMapper::resolve(CalendarableTypeEnum::EVENT, $this->row($overrides))->value,
                "ladder mismatch for {$expected}",
            );
        }
    }

    /** A cancelled class with paid registrations is real history, so the delete wins outright. */
    public function testDeletedAtOutranksTheEntireLadder(): void
    {
        $row = $this->row([
            'deleted_at' => '2026-02-01 00:00:00',
            'finalized_at' => '2026-01-01 00:00:00',
            'published_at' => '2025-12-01 00:00:00',
        ]);

        foreach (CalendarableTypeEnum::cases() as $morph) {
            $this->assertSame(EventStatusEnum::CANCELLED, EventStatusMapper::resolve($morph, $row));
        }
    }

    public function testUnpublishedClassIsDraft(): void
    {
        foreach ([CalendarableTypeEnum::LESSON, CalendarableTypeEnum::WORKSHOP] as $morph) {
            $this->assertSame(
                EventStatusEnum::DRAFT,
                EventStatusMapper::resolve($morph, $this->row(['end' => '2020-01-01 00:00:00'])),
            );
        }
    }

    public function testPublishedClassIsCompletedOnlyAfterItEnds(): void
    {
        $now = Carbon::parse('2026-06-15 12:00:00', 'UTC');

        $this->assertSame(
            EventStatusEnum::COMPLETED,
            EventStatusMapper::resolve(
                CalendarableTypeEnum::LESSON,
                $this->row(['published_at' => '2026-01-01 00:00:00', 'end' => '2026-06-14 23:00:00']),
                $now,
            ),
        );

        $this->assertSame(
            EventStatusEnum::PUBLISHED,
            EventStatusMapper::resolve(
                CalendarableTypeEnum::LESSON,
                $this->row(['published_at' => '2026-01-01 00:00:00', 'end' => '2026-06-16 01:00:00']),
                $now,
            ),
        );
    }

    /** A published row with no end date can't be proven over, so it stays live rather than closing. */
    public function testPublishedClassWithoutEndDateStaysPublished(): void
    {
        $this->assertSame(
            EventStatusEnum::PUBLISHED,
            EventStatusMapper::resolve(
                CalendarableTypeEnum::WORKSHOP,
                $this->row(['published_at' => '2026-01-01 00:00:00']),
            ),
        );
    }

    /** Only private events carry the ladder; a published_at on them must not short-circuit it. */
    public function testPrivateEventIgnoresPublishedAt(): void
    {
        $this->assertSame(
            EventStatusEnum::PENDING,
            EventStatusMapper::resolve(
                CalendarableTypeEnum::EVENT,
                $this->row(['published_at' => '2026-01-01 00:00:00']),
            ),
        );
    }

    public function testEveryResolvedStatusIsSeeded(): void
    {
        $seeded = array_map(fn (EventStatusEnum $s) => $s->value, EventStatusEnum::cases());

        foreach (CalendarableTypeEnum::cases() as $morph) {
            foreach ([[], ['deleted_at' => '2026-01-01 00:00:00'], ['published_at' => '2026-01-01 00:00:00']] as $overrides) {
                $this->assertContains(
                    EventStatusMapper::resolve($morph, $this->row($overrides))->value,
                    $seeded,
                );
            }
        }
    }
}
