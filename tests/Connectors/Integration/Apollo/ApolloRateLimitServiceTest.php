<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
