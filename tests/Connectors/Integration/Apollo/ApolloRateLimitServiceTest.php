<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Connectors\Apollo\Services\ApolloRateLimitService;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class ApolloRateLimitServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    protected function tearDown(): void
    {
        // Config writes land in cache too, which DatabaseTransactions does not roll back —
        // clear them so a partial report row can't leak into other Apollo tests.
        $company = static::$cachedUser->getCurrentCompany();
        $company->del(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value);
        $company->del(ConfigurationEnum::APOLLO_REVALIDATION->value);

        // The hourly counter lives in cache (not a DB transaction) — clear it so the
        // count can't bleed into another test.
        Cache::forget('api_hourly_rate_limit_' . (int) app(Apps::class)->getId());

        parent::tearDown();
    }

    public function test_daily_limit_reads_the_company_report(): void
    {
        $company = static::$cachedUser->getCurrentCompany();
        $service = new ApolloRateLimitService();
        $today = date('Y-m-d');

        $company->set(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value, [$today => ['total' => 5, 'success' => 5, 'processed' => 5, 'failed' => 0]]);
        $this->assertFalse($service->hasReachedDailyLimit($company));
        $this->assertSame(5, $service->dailyTotal($company));

        $company->set(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value, [$today => ['total' => 2000, 'success' => 2000, 'processed' => 2000, 'failed' => 0]]);
        $this->assertTrue($service->hasReachedDailyLimit($company));

        // A lower explicit cap trips earlier.
        $this->assertTrue($service->hasReachedDailyLimit($company, dailyLimit: 3));

        // A malformed report row (today present but no 'total' key) must not error.
        $company->set(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value, [$today => ['success' => 4]]);
        $this->assertSame(0, $service->dailyTotal($company));
        $this->assertFalse($service->hasReachedDailyLimit($company));
    }

    public function test_recently_screened_respects_the_revalidation_window(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $service = new ApolloRateLimitService();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        // Never enriched → free to screen.
        $this->assertFalse($service->hasBeenScreenedRecently($people));

        // Enriched just now → inside the default 2-month window.
        $people->set(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value, time());
        $this->assertTrue($service->hasBeenScreenedRecently($people));

        // Enriched 3 months ago → past the default window, eligible again.
        $people->set(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value, strtotime('-3 months'));
        $this->assertFalse($service->hasBeenScreenedRecently($people));

        // Widen the company window to 6 months → the same 3-month-old screen is recent again.
        $company->set(ConfigurationEnum::APOLLO_REVALIDATION->value, '-6 months');
        $this->assertTrue($service->hasBeenScreenedRecently($people));
    }

    public function test_hourly_counter_increments_and_trips_the_limit(): void
    {
        $app = app(Apps::class);
        $service = new ApolloRateLimitService();

        $this->assertSame(0, $service->hourlyCount($app));
        $this->assertFalse($service->hasReachedHourlyLimit($app));

        $this->assertSame(1, $service->recordHourlyHit($app));
        $this->assertSame(2, $service->recordHourlyHit($app));
        $this->assertSame(2, $service->hourlyCount($app));

        // Two hits is at the limit when the cap is 2.
        $this->assertTrue($service->hasReachedHourlyLimit($app, hourlyLimit: 2));
        $this->assertFalse($service->hasReachedHourlyLimit($app, hourlyLimit: 3));
    }

    public function test_pacing_delay_spreads_the_remaining_budget(): void
    {
        $service = new ApolloRateLimitService();

        // 1 of 10 used → spread 9 remaining over the hour.
        $this->assertSame(intdiv(ApolloRateLimitService::HOURLY_WINDOW, 9), $service->pacingDelay(1, 10));

        // At/over the cap → minimal floor delay.
        $this->assertSame(2, $service->pacingDelay(10, 10));
    }
}
