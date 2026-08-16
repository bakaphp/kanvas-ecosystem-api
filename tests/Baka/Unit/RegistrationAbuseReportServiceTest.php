<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Services\RegistrationAbuseReportService;
use Tests\TestCase;

class RegistrationAbuseReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): RegistrationAbuseReportService
    {
        return new RegistrationAbuseReportService(app(Apps::class));
    }

    public function testBlocksAccumulateInTheCurrentHourBucket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 10:15:00'));

        $service = $this->service();

        foreach (range(1, 4) as $i) {
            $service->blocked('dggie_sample' . $i . '@gmail.com', 'impossible_provider_address');
        }

        $this->assertSame(4, $service->blockedDuring(Carbon::parse('2026-08-16 10:59:00')));
    }

    public function testCountersAreBucketedPerHour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 10:15:00'));
        $service = $this->service();
        $service->blocked('dggie_a@gmail.com', 'impossible_provider_address');

        Carbon::setTestNow(Carbon::parse('2026-08-16 11:05:00'));
        $service->blocked('dggie_b@gmail.com', 'impossible_provider_address');
        $service->blocked('dggie_c@gmail.com', 'impossible_provider_address');

        $this->assertSame(1, $service->blockedDuring(Carbon::parse('2026-08-16 10:00:00')));
        $this->assertSame(2, $service->blockedDuring(Carbon::parse('2026-08-16 11:00:00')));
    }

    public function testAnHourWithNoBlocksReadsAsZero(): void
    {
        $this->assertSame(0, $this->service()->blockedDuring(Carbon::parse('2026-08-16 03:00:00')));
    }

    public function testReportingNeverThrowsIntoTheRegistrationPath(): void
    {
        $service = $this->service();

        $service->blocked('dggie_l9pbrxc3ex@gmail.com', 'impossible_provider_address');

        $this->assertSame(1, $service->blockedDuring(Carbon::now()));
    }
}
