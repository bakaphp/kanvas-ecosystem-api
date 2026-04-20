<?php

declare(strict_types=1);

namespace Tests\Event\Reports;

use Carbon\Carbon;
use Kanvas\Event\Reports\Services\GoalTrackingService;
use Tests\TestCase;

class GoalTrackingServiceTest extends TestCase
{
    protected GoalTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GoalTrackingService();
    }

    public function testExpectedEnrollmentForZeroGoalIsZero(): void
    {
        $this->assertSame(0, $this->service->getExpectedEnrollment(0, 5));
        $this->assertSame(0, $this->service->getExpectedEnrollment(-10, 5));
    }

    public function testExpectedEnrollmentMatchesPdfExample(): void
    {
        $goal = 30;

        $this->assertSame(2, $this->service->getExpectedEnrollment($goal, 7));
        $this->assertSame(3, $this->service->getExpectedEnrollment($goal, 6));
        $this->assertSame(6, $this->service->getExpectedEnrollment($goal, 5));
        $this->assertSame(12, $this->service->getExpectedEnrollment($goal, 4));
        $this->assertSame(18, $this->service->getExpectedEnrollment($goal, 3));
        $this->assertSame(24, $this->service->getExpectedEnrollment($goal, 2));
        $this->assertSame(30, $this->service->getExpectedEnrollment($goal, 1));
    }

    public function testExpectedEnrollmentClampsOutOfRangeWeeks(): void
    {
        $goal = 100;

        $this->assertSame(5, $this->service->getExpectedEnrollment($goal, 10));
        $this->assertSame(100, $this->service->getExpectedEnrollment($goal, 0));
        $this->assertSame(100, $this->service->getExpectedEnrollment($goal, -1));
    }

    public function testGetColorGreenWhenAchievementAboveEightySix(): void
    {
        $this->assertSame('green', $this->service->getColor(86, 100));
        $this->assertSame('green', $this->service->getColor(100, 100));
        $this->assertSame('green', $this->service->getColor(150, 100));
    }

    public function testGetColorYellowWhenAchievementBetweenSeventyAndEightyFive(): void
    {
        $this->assertSame('yellow', $this->service->getColor(70, 100));
        $this->assertSame('yellow', $this->service->getColor(80, 100));
        $this->assertSame('yellow', $this->service->getColor(85, 100));
    }

    public function testGetColorRedWhenAchievementBelowSeventy(): void
    {
        $this->assertSame('red', $this->service->getColor(0, 100));
        $this->assertSame('red', $this->service->getColor(50, 100));
        $this->assertSame('red', $this->service->getColor(69, 100));
    }

    public function testGetColorGreenWhenNoExpectation(): void
    {
        $this->assertSame('green', $this->service->getColor(0, 0));
        $this->assertSame('green', $this->service->getColor(10, 0));
    }

    public function testGetWeeksUntilReturnsZeroForPastEvent(): void
    {
        $now = Carbon::parse('2026-04-15');
        $past = Carbon::parse('2026-04-01');

        $this->assertSame(0, $this->service->getWeeksUntil($past, $now));
    }

    public function testGetWeeksUntilComputesCorrectly(): void
    {
        $now = Carbon::parse('2026-04-15');

        $this->assertSame(1, $this->service->getWeeksUntil(Carbon::parse('2026-04-18'), $now));
        $this->assertSame(2, $this->service->getWeeksUntil(Carbon::parse('2026-04-25'), $now));
        $this->assertSame(5, $this->service->getWeeksUntil(Carbon::parse('2026-05-16'), $now));
        $this->assertSame(7, $this->service->getWeeksUntil(Carbon::parse('2026-05-30'), $now));
    }

    public function testAchievementPercentageWithExpectation(): void
    {
        $this->assertSame(0.0, $this->service->getAchievementPercentage(0, 100));
        $this->assertSame(50.0, $this->service->getAchievementPercentage(50, 100));
        $this->assertSame(100.0, $this->service->getAchievementPercentage(100, 100));
        $this->assertSame(150.0, $this->service->getAchievementPercentage(150, 100));
    }

    public function testAchievementPercentageWithNoExpectation(): void
    {
        $this->assertSame(0.0, $this->service->getAchievementPercentage(0, 0));
        $this->assertSame(100.0, $this->service->getAchievementPercentage(5, 0));
    }
}
