<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum as ReynoldsConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

/**
 * Regression for the phantom-entity bug.
 *
 * jitsubai's sync path calls runWorkflowFromEntity with entity_id "0", so the
 * mutation fabricates an unsaved Lead with id=0 and hands it to this activity.
 * The Reynolds arm of the $resolvedLead match returned that phantom instead of
 * the lead the search had just found. It is a Lead, so it passed the
 * `instanceof` guard, and every post-pull side effect then ran against an empty
 * record: CreateSocialChannelsAfterPullAction no-ops on id===0, and
 * ApplyLeadClosingStatusAction has no such guard and threw into a
 * catch-and-report. Reynolds had therefore never once run its post-pull work.
 */
final class PullLeadActivityReynoldsResolvedLeadTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testResolvesTheFoundLeadRatherThanThePhantomEntity(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $company->set(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, '12345');

        try {
            $lead = $this->createOpenLead();
            $email = $lead->people->getEmails()->first()->value;

            $result = $this->runActivity($this->phantomLead($app, $company), $app, [
                'entity_id' => '4369283',
                'email' => $email,
            ]);

            $this->assertNotEmpty($result, 'the search should have found the lead');
            $this->assertSame($lead->getId(), $result[0]['id']);

            // The stamp now runs off $resolvedLead. It landed on the found lead
            // before this change too — the old code stamped it inside the Reynolds
            // branch — so this pins that moving it did not lose it, it is not what
            // proves the phantom is gone. What proves that is the shape assertion
            // in the next test, plus the fact that $resolvedLead is now the same
            // lookup the other CRM arms use.
            //
            // The side-effect half (social channels, closing status) cannot be
            // asserted here: CreateSocialChannelsAfterPullAction needs a "Sally"
            // Agent row that this fixture has no cheap way to build, so both
            // before and after it dies in the activity's catch-and-report.
            $this->assertSame(
                '4369283',
                (string) $lead->refresh()->get(CustomFieldEnum::CLIENT_ID->value)
            );
        } finally {
            $company->del(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
        }
    }

    public function testReturnsTheSharedShapeWithANumericRankNotARawEloquentArray(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $company->set(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, '12345');

        try {
            $lead = $this->createOpenLead();

            $result = $this->runActivity($this->phantomLead($app, $company), $app, [
                'email' => $lead->people->getEmails()->first()->value,
            ]);

            $this->assertNotEmpty($result);
            // rank absent was why the client drew "NaN% Match" for Reynolds.
            $this->assertIsFloat($result[0]['rank']);
            $this->assertArrayHasKey('people_id', $result[0]);
            $this->assertArrayNotHasKey('leads_status_id', $result[0], 'must not be a raw toArray()');
        } finally {
            $company->del(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
        }
    }

    private function runActivity(Lead $entity, Apps $app, array $params): array
    {
        $activity = new PullLeadActivity(
            index: 0,
            now: now()->toDateTimeString(),
            storedWorkflow: new StoredWorkflow(),
            arguments: []
        );

        return $activity->execute($entity, $app, $params);
    }

    /**
     * Built exactly the way WorkflowMutationManagement does for entity_id "0".
     */
    private function phantomLead(Apps $app, $company): Lead
    {
        $entity = new Lead();
        $entity->fill([
            'id' => 0,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        return $entity;
    }

    private function createOpenLead(): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        $statusId = DB::connection('crm')->table('leads_status')->insertGetId([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Open',
            'is_default' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => $statusId]);
    }
}
