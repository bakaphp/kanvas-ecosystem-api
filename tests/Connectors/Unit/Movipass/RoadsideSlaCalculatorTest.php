<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Movipass;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Support\RoadsideEvidenceRules;
use Kanvas\Connectors\Movipass\Support\RoadsideSlaCalculator;
use Tests\TestCase;

final class RoadsideSlaCalculatorTest extends TestCase
{
    public function testStagesAreMeasuredFromTheStateHistory(): void
    {
        $metrics = $this->byMetric(RoadsideSlaCalculator::forCase([
            'state_history' => [
                $this->entry('CREATED', '10:00'),
                $this->entry('PENDING_ASSIGNMENT', '10:01'),
                $this->entry('TECHNICIAN_ASSIGNED', '10:09'),
                $this->entry('EN_ROUTE', '10:10'),
                $this->entry('ON_SITE', '10:30'),
                $this->entry('IN_PROGRESS', '10:32'),
                $this->entry('COMPLETED', '11:00'),
            ],
        ]));

        $this->assertSame(1.0, $metrics['dispatch']['minutes']);
        $this->assertSame(8.0, $metrics['acceptance']['minutes']);
        $this->assertTrue($metrics['acceptance']['breached']);
        $this->assertSame(23.0, $metrics['arrival']['minutes']);
        $this->assertFalse($metrics['arrival']['breached']);
        $this->assertSame(28.0, $metrics['resolution']['minutes']);
        $this->assertArrayNotHasKey('cabin_review', $metrics);
    }

    public function testARunningStageCountsUpToNowAndUsesTheConfiguredThreshold(): void
    {
        $metrics = $this->byMetric(RoadsideSlaCalculator::forCase(
            ['state_history' => [
                $this->entry('CREATED', '10:00'),
                $this->entry('PENDING_ASSIGNMENT', '10:00'),
            ]],
            ['acceptance' => 15],
            Carbon::parse('2026-11-18 10:12:00'),
        ));

        $this->assertTrue($metrics['acceptance']['running']);
        $this->assertSame(12.0, $metrics['acceptance']['minutes']);
        $this->assertSame(15.0, $metrics['acceptance']['threshold_minutes']);
        $this->assertFalse($metrics['acceptance']['breached']);
    }

    public function testAStageACancellationCutsShortStopsWhereTheCaseClosed(): void
    {
        $metrics = $this->byMetric(RoadsideSlaCalculator::forCase(
            ['state_history' => [
                $this->entry('CREATED', '10:00'),
                $this->entry('PENDING_ASSIGNMENT', '10:00'),
                $this->entry('CANCELLED', '10:03'),
            ]],
            now: Carbon::parse('2026-11-18 18:00:00'),
        ));

        $this->assertFalse($metrics['acceptance']['running']);
        $this->assertSame(3.0, $metrics['acceptance']['minutes']);
    }

    public function testClosingNeedsBothPhotoSetsAndTheComment(): void
    {
        $case = ['service_type' => 'light_tow', 'evidence' => [
            ['phase' => 'before'], ['phase' => 'before'], ['phase' => 'before'], ['phase' => 'before'],
            ['phase' => 'after'],
        ]];

        $this->assertSame(['1 of 4 after photos', 'the operative comment'], RoadsideEvidenceRules::missingForClosing($case));

        $case['service_type'] = 'jump_start';
        $case['evidence'][] = ['phase' => 'after'];
        $case['operative_comments'] = [['comment' => 'Battery replaced']];

        $this->assertSame([], RoadsideEvidenceRules::missingForClosing($case));
    }

    private function entry(string $state, string $time): array
    {
        return ['to' => $state, 'at' => Carbon::parse('2026-11-18 ' . $time . ':00')->toISOString()];
    }

    private function byMetric(array $metrics): array
    {
        return array_column($metrics, null, 'metric');
    }
}
