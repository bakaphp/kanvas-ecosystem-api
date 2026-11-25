<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Tests\Connectors\Integration\Salesforce\Concerns\CreatesSalesforceIntegration;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

final class SalesforceBackfillCommandTest extends TestCase
{
    use CreatesSalesforceIntegration;
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'workflow'];

    public function testBothObjectsStartOrganizationsThenPeople(): void
    {
        $company = $this->freshCompany();
        $integrationCompany = $this->salesforceIntegrationFor($company);
        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
        ])->assertSuccessful();

        Queue::assertPushed(RunIntegrationImportJob::class, 1);
        $runs = IntegrationImport::where('integrations_companies_id', $integrationCompany->getId())->get();
        $this->assertSame(['organizations'], $runs->pluck('entity')->all());
        $this->assertSame(['then_people' => true], $runs->first()->options);
    }

    public function testARowWrittenBeforeAppOwnershipIsFound(): void
    {
        $company = $this->freshCompany();
        $integrationCompany = $this->salesforceIntegrationFor($company);
        $integrationCompany->apps_id = null;
        $integrationCompany->save();
        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            '--objects' => 'Account',
        ])->assertSuccessful();

        Queue::assertPushed(
            RunIntegrationImportJob::class,
            fn (RunIntegrationImportJob $job) => $job->run->integrations_companies_id === $integrationCompany->getId()
        );
    }

    public function testTheAppsOwnRowWinsOverOneWithoutAnApp(): void
    {
        $company = $this->freshCompany();
        $legacy = $this->salesforceIntegrationFor($company);
        $legacy->apps_id = null;
        $legacy->save();
        $owned = $this->salesforceIntegrationFor($company);

        $this->assertSame(
            $owned->getId(),
            IntegrationsCompany::getActiveByIntegrationName($company, app(Apps::class), 'salesforce')?->getId()
        );
    }

    public function testACompanyWithoutAnOwnerFails(): void
    {
        $company = $this->freshCompany();
        $this->salesforceIntegrationFor($company);
        $company->users_id = 999999999;
        $company->save();
        Queue::fake();

        $this->artisan('kanvas:salesforce-backfill', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
        ])
            ->expectsOutputToContain("Company {$company->getId()} has no owner to run the import as.")
            ->assertExitCode(1);

        Queue::assertNothingPushed();
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

    public function testARunAlreadyInProgressIsReported(): void
    {
        $company = $this->freshCompany();
        $this->salesforceIntegrationFor($company);
        Queue::fake();
        $arguments = ['app_id' => app(Apps::class)->getId(), 'company_id' => $company->getId(), '--objects' => 'Account'];
        $this->artisan('kanvas:salesforce-backfill', $arguments)->assertSuccessful();

        $this->artisan('kanvas:salesforce-backfill', [...$arguments, '--objects' => 'Account,Contact'])
            ->expectsOutputToContain('A organizations import is already in progress for this integration.')
            ->assertExitCode(1);

        Queue::assertPushed(RunIntegrationImportJob::class, 1);
    }
}
