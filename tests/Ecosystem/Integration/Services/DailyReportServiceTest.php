<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Services;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Services\DailyReportService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class DailyReportServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up test data
        DailyReportService::cleanup(Carbon::now()->format('Y-m-d'));
        parent::tearDown();
    }

    public function testTrackAndGetMetric(): void
    {
        $app = app(Apps::class);
        $user = Users::where('id', '>', 0)->first();
        $company = $user->getCurrentCompany();

        // Track a metric
        $result = DailyReportService::track($app, $company, 'test_metric');
        $this->assertEquals(1, $result);

        // Track again to increment
        $result = DailyReportService::track($app, $company, 'test_metric');
        $this->assertEquals(2, $result);

        // Get the metric
        $value = DailyReportService::get($app, $company, 'test_metric');
        $this->assertEquals(2, $value);
    }

    public function testGetMetricsWithTotals(): void
    {
        $app = app(Apps::class);
        $user = Users::where('id', '>', 0)->first();
        $company = $user->getCurrentCompany();

        // Track multiple metrics
        DailyReportService::track($app, $company, 'ai_messages', 5);
        DailyReportService::track($app, $company, 'ai_follow_ups', 3);

        // Get all metrics with totals
        $data = DailyReportService::getMetricsWithTotals();

        $this->assertArrayHasKey('by_app', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertEquals(5, $data['totals']['ai_messages']);
        $this->assertEquals(3, $data['totals']['ai_follow_ups']);
    }
}
