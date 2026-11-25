<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Tests\Connectors\Integration\Salesforce\Concerns\CreatesSalesforceIntegration;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

final class SalesforceBackfillCommandTest extends TestCase
{
    use CreatesSalesforceIntegration;
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'workflow'];

    public function testQueuesOneRunPerObject(): void
    {
        $company = $this->freshCompany();
        $integrationCompany = $this->salesforceIntegrationFor($company);
        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
        ])->assertSuccessful();

        Queue::assertPushed(RunIntegrationImportJob::class, 2);
        $entities = IntegrationImport::where('integrations_companies_id', $integrationCompany->getId())
            ->orderBy('id')
            ->pluck('entity')
            ->all();
        $this->assertSame(['organizations', 'people'], $entities);
    }

    public function testOnlyTheRequestedObjectIsQueued(): void
    {
        $company = $this->freshCompany();
        $this->salesforceIntegrationFor($company);
        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            '--objects' => 'Contact',
        ])->assertSuccessful();

        Queue::assertPushed(RunIntegrationImportJob::class, 1);
        Queue::assertPushed(fn (RunIntegrationImportJob $job) => $job->run->entity === 'people');
    }

    public function testFailsWhenSalesforceIsNotConnected(): void
    {
        $company = $this->freshCompany();
        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
        ])
            ->expectsOutputToContain("Salesforce is not connected for company {$company->getId()}.")
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function testAnUnknownObjectFails(): void
    {
        $company = $this->freshCompany();
        $this->salesforceIntegrationFor($company);
        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            '--objects' => 'Opportunity',
        ])->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function testARunAlreadyInProgressDoesNotStopTheNextObject(): void
    {
        $company = $this->freshCompany();
        $this->salesforceIntegrationFor($company);
        Queue::fake();
        $arguments = ['app_id' => app(Apps::class)->getId(), 'company_id' => $company->getId(), '--objects' => 'Account'];
        $this->artisan('kanvas:salesforce-backfill', $arguments)->assertSuccessful();

        $this->artisan('kanvas:salesforce-backfill', [...$arguments, '--objects' => 'Account,Contact'])
            ->assertExitCode(1);

        Queue::assertPushed(RunIntegrationImportJob::class, 2);
    }
}
