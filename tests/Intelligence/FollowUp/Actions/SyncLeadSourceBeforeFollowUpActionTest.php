<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\CrmLeadSourceSynchronizer;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Actions\SyncLeadSourceBeforeFollowUpAction;
use Kanvas\Intelligence\FollowUp\Enums\LeadSourceSyncOutcomeEnum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

/**
 * The pre-sync is the only thing standing between the follow-up engine and a lead the CRM closed
 * without telling us, so each branch is pinned: not syncable, fresh marker, stale marker, failure.
 */
class SyncLeadSourceBeforeFollowUpActionTest extends TestCase
{
    use DatabaseTransactions;
    use MockeryPHPUnitIntegration;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testLeadWithoutASyncableSourceIsNotApplicableAndNeverPulled(): void
    {
        $lead = $this->seedLead();
        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(false);
        $synchronizer->shouldNotReceive('sync');

        $outcome = new SyncLeadSourceBeforeFollowUpAction($lead, $synchronizer)->execute();

        $this->assertSame(LeadSourceSyncOutcomeEnum::NOT_APPLICABLE, $outcome);
        $this->assertNull($lead->get(LeadConfigurationEnum::FOLLOW_UP_SOURCE_SYNCED_AT->value));
    }

    public function testStaleLeadIsPulledAndStamped(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');
        $lead = $this->seedLead();
        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(true);
        $synchronizer->shouldReceive('sync')->once()->with(Mockery::on(fn (Lead $l) => $l->getId() === $lead->getId()));

        $outcome = new SyncLeadSourceBeforeFollowUpAction($lead, $synchronizer)->execute();

        $this->assertSame(LeadSourceSyncOutcomeEnum::SYNCED, $outcome);
        $this->assertSame(
            Carbon::now()->toIso8601String(),
            $lead->get(LeadConfigurationEnum::FOLLOW_UP_SOURCE_SYNCED_AT->value)
        );
        Carbon::setTestNow();
    }

    public function testRecentlySyncedLeadIsNotPulledAgainWithinTheInterval(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');
        $lead = $this->seedLead();
        $lead->set(LeadConfigurationEnum::FOLLOW_UP_SOURCE_SYNCED_AT->value, Carbon::now()->subMinutes(20)->toIso8601String());

        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(true);
        $synchronizer->shouldNotReceive('sync');

        $outcome = new SyncLeadSourceBeforeFollowUpAction($lead, $synchronizer)->execute();

        $this->assertSame(LeadSourceSyncOutcomeEnum::FRESH, $outcome);
        Carbon::setTestNow();
    }

    public function testCompanyIntervalOverridesTheDefault(): void
    {
        Carbon::setTestNow('2026-10-13 10:00:00');
        $lead = $this->seedLead();
        $lead->company->set(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_SYNC_INTERVAL_MINUTES->value, 10);
        $lead->set(LeadConfigurationEnum::FOLLOW_UP_SOURCE_SYNCED_AT->value, Carbon::now()->subMinutes(20)->toIso8601String());

        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(true);
        $synchronizer->shouldReceive('sync')->once();

        try {
            $outcome = new SyncLeadSourceBeforeFollowUpAction($lead, $synchronizer)->execute();
        } finally {
            // Company settings live in Redis too and outlive the transaction.
            $lead->company->del(IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_SYNC_INTERVAL_MINUTES->value);
            Carbon::setTestNow();
        }

        $this->assertSame(LeadSourceSyncOutcomeEnum::SYNCED, $outcome);
    }

    public function testPullFailureIsReportedAndLeavesTheMarkerUntouched(): void
    {
        $lead = $this->seedLead();
        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(true);
        $synchronizer->shouldReceive('sync')->once()->andThrow(new RuntimeException('CRM down'));

        $outcome = new SyncLeadSourceBeforeFollowUpAction($lead, $synchronizer)->execute();

        $this->assertSame(LeadSourceSyncOutcomeEnum::FAILED, $outcome);
        $this->assertNull(
            $lead->get(LeadConfigurationEnum::FOLLOW_UP_SOURCE_SYNCED_AT->value),
            'A failed pull must not look fresh on the next tick.'
        );
    }

    private function seedLead(): Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
    }
}
