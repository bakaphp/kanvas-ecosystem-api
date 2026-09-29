<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Reporting\IntrasGoalPolicy;
use PHPUnit\Framework\TestCase;

/**
 * INTRAS's definition of "on track", which is not the platform's.
 *
 * `Kanvas\Event\Reports\Services\GoalTrackingService` keeps a generic seven-week
 * 5/10/20/40/60/80/100 curve and a raw headcount. Both INTRAS documents describe a five-week
 * curve and an expected-attendance estimate instead, so those rules live in the connector and
 * the platform is left alone.
 */
class IntrasGoalPolicyTest extends TestCase
{
    /**
     * The per-week shares and the cumulative curve are the same curve read two ways — the
     * running total of one is the other. That is the check that neither was mistranscribed.
     */
    public function testTheWeeklySharesAccumulateIntoTheCumulativeCurve(): void
    {
        $running = 0.0;

        foreach ([5, 4, 3, 2, 1] as $week) {
            $running += IntrasGoalPolicy::WEEKLY_SHARE[$week];

            $this->assertEqualsWithDelta(
                IntrasGoalPolicy::CUMULATIVE_SHARE[$week],
                $running,
                0.0001,
                sprintf('week %d: the shares must sum to the cumulative curve', $week)
            );
        }

        $this->assertEqualsWithDelta(1.0, $running, 0.0001, 'the five shares must sum to 100%');
    }

    public function testTheCumulativeCurveMatchesTheSpecifiedPercentages(): void
    {
        $this->assertSame(25, IntrasGoalPolicy::expectedEnrolment(100, 5));
        $this->assertSame(45, IntrasGoalPolicy::expectedEnrolment(100, 4));
        $this->assertSame(75, IntrasGoalPolicy::expectedEnrolment(100, 3));
        $this->assertSame(90, IntrasGoalPolicy::expectedEnrolment(100, 2));
        $this->assertSame(100, IntrasGoalPolicy::expectedEnrolment(100, 1));
    }

    /**
     * The gap that made this worth fixing: three weeks out, the platform curve expects 60 and
     * INTRAS expects 75, so an event at 65 reads green when INTRAS would call it red.
     */
    public function testThreeWeeksOutDiffersFromThePlatformCurve(): void
    {
        $this->assertSame(75, IntrasGoalPolicy::expectedEnrolment(100, 3));
        $this->assertSame(60, (int) round(100 * 0.60), 'the platform curve, for contrast');
    }

    public function testAGoalOfZeroExpectsNothing(): void
    {
        $this->assertSame(0, IntrasGoalPolicy::expectedEnrolment(0, 3));
    }

    public function testWeeksOutsideTheCurveClampToItsEnds(): void
    {
        $this->assertSame(25, IntrasGoalPolicy::expectedEnrolment(100, 9));
        $this->assertSame(100, IntrasGoalPolicy::expectedEnrolment(100, 0));
    }

    /**
     * `0.95×(Confirmado + …) + 0.6×Reservados`, and nothing else contributes.
     */
    public function testTheTotalIsWeightedExpectedAttendanceNotAHeadcount(): void
    {
        $this->assertSame(16, IntrasGoalPolicy::weightedTotal([
            'confirmado' => 10,
            'reservado' => 10,
            'cancelado' => 10,
        ]));
    }

    public function testATypeOutsideTheMapContributesNothing(): void
    {
        $this->assertSame(0, IntrasGoalPolicy::weightedTotal([
            'cancelado' => 50,
            'cortesia' => 20,
            'invitado-sponsor' => 10,
            'empleadoa' => 5,
        ]));
    }

    /**
     * All three soft-commitment types carry the same weight.
     *
     * `INTERESADO EVENTO` used to be the exception, on the grounds that neither document names
     * the string. That left the largest of the three (1,321 rows) at 0 while the two smallest
     * (537 and 57) counted at 0.6, and the data separates them on nothing: in-place conversion
     * 0.0/0.7/0.0%, person-ever-participated 73.0/76.5/56.1%, all three still in use since 2024.
     */
    public function testEverySoftCommitmentTypeCarriesTheSameWeight(): void
    {
        $weights = IntrasGoalPolicy::TYPE_WEIGHTS;

        $this->assertSame(0.60, $weights['reservado']);
        $this->assertSame(0.60, $weights['interesado']);
        $this->assertSame(0.60, $weights['interesado-evento']);

        $this->assertSame(
            IntrasGoalPolicy::weightedTotal(['interesado' => 100]),
            IntrasGoalPolicy::weightedTotal(['interesado-evento' => 100]),
            'naming one of them differently in SIPGO must not change the curve'
        );
    }

    /**
     * The three soft types sum into the same bucket rather than being read one at a time.
     */
    public function testSoftAndFirmCommitmentsCombine(): void
    {
        $this->assertSame(
            94,
            IntrasGoalPolicy::weightedTotal([
                'confirmado' => 80,
                'interesado-evento' => 20,
                'reservado' => 10,
                'cancelado' => 40,
            ]),
            '0.95 x 80 + 0.6 x 30'
        );
    }
}
