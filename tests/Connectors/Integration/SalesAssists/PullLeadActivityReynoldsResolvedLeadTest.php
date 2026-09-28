<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum as ReynoldsConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use ReflectionClass;
use Tests\TestCase;

/**
 * The Reynolds arm of PullLeadActivity, driven end to end. It touches no
 * external API — Reynolds prospects arrive by webhook, so its "pull" is a local
 * candidate search — which is what makes an integration-level test cheap here.
 *
 * The channel assertion is the regression guard from #11228: the arm used to
 * resolve the incoming $entity, which on a sync pull is a stub Lead with id 0,
 * so CreateSocialChannelsAfterPullAction hit its `id === 0` guard and ReyRey
 * dealers never got their SMS/email channels. That fix is preserved through the
 * rewrite that replaced findReynoldsLead() with FindLeadCandidatesAction, so the
 * guard has to keep holding against a different resolution path.
 */
class PullLeadActivityReynoldsResolvedLeadTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'intelligence', 'ecosystem'];

    public function testReynoldsPullCreatesSocialChannelsForTheMatchedLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, '12345');

        try {
            $this->createSallyAgent($app, $company);
            $lead = $this->createOpenLead();
            $phone = Str::sanitizePhoneNumber($lead->people->getCellPhones()->first()->value);

            $this->runActivity($app, $company, ['phone' => $phone, 'user' => $user]);

            $channelNames = $lead->socialChannels()->pluck('name')->all();

            $this->assertContains(
                'Sms ' . $lead->getId(),
                $channelNames,
                'Reynolds pull must open channels on the matched lead, not on the id=0 stub entity.'
            );
            $this->assertContains('Email ' . $lead->getId(), $channelNames);
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

            $result = $this->runActivity($app, $company, [
                'email' => $lead->people->getEmails()->first()->value,
            ]);

            $this->assertNotEmpty($result);
            $this->assertSame($lead->getId(), $result[0]['id']);
            // rank absent was why the picker drew "NaN% Match" for Reynolds — the
            // arm used to hand back a raw $lead->toArray().
            $this->assertIsFloat($result[0]['rank']);
            $this->assertArrayHasKey('people_id', $result[0]);
            $this->assertArrayNotHasKey('leads_status_id', $result[0], 'must not be a raw toArray()');
        } finally {
            $company->del(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
        }
    }

    public function testStampsTheInboundClientIdOnTheResolvedLead(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $company->set(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, '12345');

        try {
            $lead = $this->createOpenLead();

            $this->runActivity($app, $company, [
                'entity_id' => '4369283',
                'email' => $lead->people->getEmails()->first()->value,
            ]);

            $this->assertSame(
                '4369283',
                (string) $lead->refresh()->get(CustomFieldEnum::CLIENT_ID->value)
            );
        } finally {
            $company->del(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
        }
    }

    private function runActivity(Apps $app, Companies $company, array $params): array
    {
        // Built the way WorkflowMutationManagement does for entity_id "0".
        $stub = new Lead();
        $stub->companies_id = $company->getId();

        return new ReflectionClass(PullLeadActivity::class)->newInstanceWithoutConstructor()
            ->execute($stub, $app, $params);
    }

    private function createSallyAgent(Apps $app, Companies $company): void
    {
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['name' => 'Reynolds Pull Test ' . Str::random(6)]);

        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sally',
                'agent_type_id' => $agentType->getId(),
                'user_id' => auth()->user()->getId(),
            ]);
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
