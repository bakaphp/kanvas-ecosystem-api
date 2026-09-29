<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Analytics\Reporting\Jobs\RefreshReportRowsJob;
use Kanvas\Analytics\Reporting\Support\ReportRefreshSuppressor;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Tests\TestCase;

/**
 * Saving an entity queues the refresh of the flat rows it invalidated.
 *
 * Worth its own test because `RefreshesReportRows::dispatchReportRefresh()` ends in
 * `catch (Throwable) {}` — by design, since a stale report row is recoverable and a failed save
 * is not. The cost is that a break in the fan-out is completely silent: no exception, no log, the
 * save succeeds, and the flat tables quietly drift until the nightly rebuild. Nothing else covers
 * it — every other test writes with `saveQuietly()`, which skips observers altogether.
 */
class ReportFanOutTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        // The registry asks the provider, and the provider reads enablement from the connector's
        // own DB settings. Without them the app has no definitions and nothing fans out — which
        // is the behaviour a different test would assert, not this one.
        $this->kanvasApp->set(ConfigurationEnum::INTRAS_DB_HOST->value, '127.0.0.1');
        $this->kanvasApp->set(ConfigurationEnum::INTRAS_DB_DATABASE->value, 'intras');
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(ConfigurationEnum::INTRAS_DB_HOST->value);
        $this->kanvasApp->del(ConfigurationEnum::INTRAS_DB_DATABASE->value);

        parent::tearDown();
    }

    public function test_saving_a_person_queues_a_refresh_of_their_own_row(): void
    {
        Queue::fake();

        $person = $this->makePerson();
        $person->firstname = 'Renamed';
        $person->save();

        Queue::assertPushed(
            RefreshReportRowsJob::class,
            fn (RefreshReportRowsJob $job): bool => $job->model === 'ejecutivo'
                && in_array((int) $person->getId(), $job->ids, true)
        );
    }

    /**
     * The fan-out that justifies the queue: renaming one organization invalidates a row per
     * person in it, which is why this cannot run inline on the save.
     */
    public function test_saving_an_organization_queues_a_refresh_for_each_of_its_people(): void
    {
        $organization = $this->makeOrganization();
        $first = $this->makePerson();
        $second = $this->makePerson();

        OrganizationPeople::addPeopleToOrganization($organization, $first);
        OrganizationPeople::addPeopleToOrganization($organization, $second);

        Queue::fake();

        $organization->name = 'Renamed ' . uniqid();
        $organization->save();

        Queue::assertPushed(
            RefreshReportRowsJob::class,
            function (RefreshReportRowsJob $job) use ($first, $second): bool {
                return $job->model === 'ejecutivo'
                    && in_array((int) $first->getId(), $job->ids, true)
                    && in_array((int) $second->getId(), $job->ids, true);
            }
        );
    }

    public function test_the_job_carries_the_saving_entitys_own_app_and_company(): void
    {
        Queue::fake();

        $person = $this->makePerson();
        $person->firstname = 'Tenant';
        $person->save();

        Queue::assertPushed(
            RefreshReportRowsJob::class,
            fn (RefreshReportRowsJob $job): bool => $job->app->getId() === $this->kanvasApp->getId()
                && $job->company->getId() === $this->company->getId()
        );
    }

    /**
     * A bulk import saves tens of thousands of rows and rebuilds once at the end; dispatching per
     * row would queue a job for every save, each rebuilding what the next one invalidates.
     */
    public function test_a_suppressed_block_queues_nothing(): void
    {
        Queue::fake();

        ReportRefreshSuppressor::while(function (): void {
            $person = $this->makePerson();
            $person->firstname = 'Bulk';
            $person->save();
        });

        Queue::assertNotPushed(RefreshReportRowsJob::class);
    }

    public function test_suppression_is_restored_when_the_block_throws(): void
    {
        try {
            ReportRefreshSuppressor::while(function (): void {
                throw new \RuntimeException('bulk import blew up');
            });
        } catch (\RuntimeException) {
        }

        Queue::fake();

        $person = $this->makePerson();
        $person->firstname = 'AfterFailure';
        $person->save();

        Queue::assertPushed(RefreshReportRowsJob::class);
    }

    private function makePerson(): People
    {
        $person = new People();
        $person->apps_id = $this->kanvasApp->getId();
        $person->companies_id = $this->company->getId();
        $person->users_id = static::$cachedUser->getId();
        $person->firstname = 'FanOut';
        $person->lastname = 'Target ' . uniqid();
        $person->name = $person->firstname . ' ' . $person->lastname;
        // saveQuietly() skips UuidTrait's creating hook, and the column is NOT NULL with no
        // default — a local database that has drifted a default hides this until CI.
        $person->generateUuidIfMissing()->saveQuietly();

        return $person;
    }

    private function makeOrganization(): Organization
    {
        $organization = new Organization();
        $organization->apps_id = $this->kanvasApp->getId();
        $organization->companies_id = $this->company->getId();
        $organization->users_id = static::$cachedUser->getId();
        $organization->name = 'FanOut Org ' . uniqid();
        // saveQuietly() skips UuidTrait's creating hook, and the column is NOT NULL with no
        // default — a local database that has drifted a default hides this until CI.
        $organization->generateUuidIfMissing()->saveQuietly();

        return $organization;
    }
}
