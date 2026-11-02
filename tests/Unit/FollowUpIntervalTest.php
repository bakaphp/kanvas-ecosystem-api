<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\FollowUp\Traits\HasFollowUpState;
use PHPUnit\Framework\TestCase;

final class FollowUpIntervalTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testIntervalUsesLatestActivityAndCreationOnlyAsFallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        $cases = [
            [60, 120, 180, 60],
            [180, 60, 120, 60],
            [180, 120, 60, 60],
            [null, null, null, 1440],
            [null, 90, null, 90],
            [null, null, 90, 90],
            [90, null, null, 90],
            [-60, 90, 120, 0],
        ];

        foreach ($cases as [$stageAge, $sentAge, $inboundAge, $expected]) {
            $entity = $this->entity();
            $entity->created_at = Carbon::now()->subDay();
            $entity->state = [
                'stage_entered_at' => $stageAge !== null ? Carbon::now()->subMinutes($stageAge)->toIso8601String() : null,
                'last_at' => $sentAge !== null ? Carbon::now()->subMinutes($sentAge)->toIso8601String() : null,
            ];

            $this->assertSame($expected, $entity->followUpSilenceMinutesSince(
                $inboundAge !== null ? Carbon::now()->subMinutes($inboundAge) : null
            ));
        }
    }

    public function testStageResetStartsFullThreeDayWaitEvenWithOldInbound(): void
    {
        $enteredAt = Carbon::parse('2026-10-13 12:00:00', 'UTC');
        Carbon::setTestNow($enteredAt);
        $entity = $this->entity();
        $entity->created_at = $enteredAt->copy()->subMonth();
        $entity->bumpFollowUp(['sms'], null);
        $entity->resetFollowUpState();
        $inbound = $enteredAt->copy()->subDays(10);

        $this->assertNull($entity->getFollowUpLastAt());
        $this->assertSame(0, $entity->followUpSilenceMinutesSince($inbound));
        Carbon::setTestNow($enteredAt->copy()->addMinutes(4319)->addSeconds(59));
        $this->assertSame(4319, $entity->followUpSilenceMinutesSince($inbound));
        Carbon::setTestNow($enteredAt->copy()->addMinutes(4320));
        $this->assertSame(4320, $entity->followUpSilenceMinutesSince($inbound));
        $this->assertSame(10, $entity->followUpSilenceMinutesSince(Carbon::now()->subMinutes(10)));
    }

    public function testMissingOrInvalidDatesCannotMakeFollowUpImmediatelyDue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        $entity = $this->entity();
        $this->assertSame(0, $entity->followUpSilenceMinutesSince(null));
        $entity->state = ['stage_entered_at' => 'invalid', 'last_at' => 'invalid'];
        $entity->created_at = Carbon::now()->subMinutes(15);
        $this->assertSame(15, $entity->followUpSilenceMinutesSince(null));
    }

    public function testCreationFallbackPreservesTimezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        $entity = $this->entity();
        $entity->created_at = Carbon::parse('2026-10-13 07:00:00', 'America/Santo_Domingo');

        $this->assertSame(60, $entity->followUpSilenceMinutesSince(null));
    }

    private function entity(): object
    {
        return new class () {
            use HasFollowUpState;

            public ?Carbon $created_at = null;
            public array $state = [];

            public function get(string $key): array
            {
                return $this->state;
            }

            public function set(string $key, array $value): void
            {
                $this->state = $value;
            }
        };
    }
}
