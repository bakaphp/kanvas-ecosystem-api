<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Jobs\SalesforceBackfillImportJob;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class SalesforceBackfillCommandTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testDispatchesAnImportJobPerRequestedObject(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);
        $this->fakeSalesforceOAuth();

        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response([
                'totalSize' => 1,
                'done' => true,
                'records' => [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp']],
            ], 200),
        ]);

        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => $app->getId(),
            'company_id' => $company->getId(),
        ])->assertSuccessful();

        Queue::assertPushed(SalesforceBackfillImportJob::class, 2);
    }

    public function testOnlyDispatchesTheRequestedObject(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $this->configureSalesforce($company);
        $this->fakeSalesforceOAuth();

        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/query*' => Http::response([
                'totalSize' => 1,
                'done' => true,
                'records' => [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp']],
            ], 200),
        ]);

        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => $app->getId(),
            'company_id' => $company->getId(),
            '--objects' => 'Account',
        ])->assertSuccessful();

        Queue::assertPushed(SalesforceBackfillImportJob::class, 1);
        Queue::assertPushed(function (SalesforceBackfillImportJob $job) {
            return $job->salesforceObjectType === 'Account';
        });
    }
}
