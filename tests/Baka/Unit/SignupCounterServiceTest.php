<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Support\Carbon;
use Kanvas\Auth\Services\SignupCounterService;
use Tests\Stubs\Auth\InMemorySettingsApp;
use Tests\TestCase;

class SignupCounterServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Carbon::setTestNow('2026-08-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function counter(int $appId = 1): SignupCounterService
    {
        return new SignupCounterService(InMemorySettingsApp::withSettings([], $appId));
    }

    /**
     * The regression that motivated the sliding window: the old counter anchored
     * its TTL to the first hit, so a signup every 10 minutes expired a 1-hour
     * window between every hit and the count never passed 1.
     */
    public function testHitsSpreadAcrossTheWindowAccumulateInsteadOfExpiring(): void
    {
        $counter = $this->counter();
        $total = 0;

        for ($i = 0; $i < 6; $i++) {
            $total = $counter->hitInWindow('prefix:nooow', 3600);
            Carbon::setTestNow(Carbon::now()->addMinutes(10));
        }

        $this->assertSame(6, $total);
    }

    public function testHitsOlderThanTheWindowFallOutOfTheCount(): void
    {
        $counter = $this->counter();

        $counter->hitInWindow('prefix:nooow', 600);
        Carbon::setTestNow(Carbon::now()->addSeconds(700));

        $this->assertSame(1, $counter->hitInWindow('prefix:nooow', 600));
    }

    public function testTheWindowSlidesRatherThanResettingWholesale(): void
    {
        $counter = $this->counter();

        $counter->hitInWindow('prefix:nooow', 600);
        Carbon::setTestNow(Carbon::now()->addSeconds(500));
        $counter->hitInWindow('prefix:nooow', 600);

        // The first hit is still inside the window here.
        $this->assertSame(3, $counter->hitInWindow('prefix:nooow', 600));

        // ...and drops out on its own once it ages past 600s, leaving the two
        // newer ones rather than clearing the whole counter.
        Carbon::setTestNow(Carbon::now()->addSeconds(200));
        $this->assertSame(3, $counter->hitInWindow('prefix:nooow', 600));
    }

    public function testBucketsAreScopedPerApp(): void
    {
        $this->counter()->hitInWindow('prefix:nooow', 600);
        $this->counter()->hitInWindow('prefix:nooow', 600);

        $this->assertSame(1, $this->counter(appId: 2)->hitInWindow('prefix:nooow', 600));
    }

    public function testUnrelatedBucketsDoNotShareACount(): void
    {
        $counter = $this->counter();

        $counter->hitInWindow('prefix:nooow', 600);
        $counter->hitInWindow('prefix:nooow', 600);

        $this->assertSame(1, $counter->hitInWindow('domain:example.com', 600));
    }

    /**
     * `hit` keeps the retention-counter behaviour the abuse reporter depends on,
     * where the caller names the period itself and the TTL is only how long the
     * number stays readable.
     */
    public function testHitStillCountsAgainstACallerNamedBucket(): void
    {
        $counter = $this->counter();

        $counter->hit('blocked:2026-08-28T10', 172800);
        $counter->hit('blocked:2026-08-28T10', 172800);

        $this->assertSame(2, $counter->read('blocked:2026-08-28T10'));
    }
}
