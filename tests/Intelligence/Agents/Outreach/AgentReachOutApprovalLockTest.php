<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutOnChannelAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\Intelligence\SalesEmailEnvelopeNeuronAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * Human-approval gate on outbound reach-out: when the company AI mode is APPROVAL, an email
 * reach-out must persist as a locked draft and NOT ship — a human approves it later via
 * ApproveAgentMessageAction. Scoped to email (the only verb that approval path actuates today).
 */
class AgentReachOutApprovalLockTest extends TestCase
{
    use DatabaseTransactions;

    public function testEmailReachOutIsLockedAndNotSentWhenCompanyRequiresApproval(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());
        $company->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::APPROVAL->value);

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'alan@kanvas.dev',
            'weight' => 1,
        ]);

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales Email Approval (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesEmailEnvelopeNeuronAgentStub::class,
            ]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sally Outreach',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Return the structured email envelope',
                'output_format' => 'json',
            ]);

        $outbound = null;

        try {
            $outbound = new AgentReachOutOnChannelAction(
                lead: $lead,
                agent: $agent,
                channelType: 'email',
                recipient: 'alan@kanvas.dev',
            )->execute();
        } catch (Throwable) {
            // No post-persist hook should throw on the locked path, but stay defensive.
        }

        $this->assertNotNull($outbound);
        $this->assertTrue($outbound->isLocked(), 'Email draft must be locked in APPROVAL mode');

        // Held for review → nothing ships until a human approves it.
        Notification::assertNothingSent();

        $persisted = Message::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereJsonContains('message->from_ia', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($persisted);
        $this->assertSame(1, (int) $persisted->is_locked);
    }

    public function testEmailReachOutShipsImmediatelyWhenApprovalModeOff(): void
    {
        Notification::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());
        $company->set(IntelligenceConfigurationEnum::AGENT_AI_MODE->value, IntelligenceModeEnum::FULL_ON->value);

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'alan@kanvas.dev',
            'weight' => 1,
        ]);

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales Email No Approval (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesEmailEnvelopeNeuronAgentStub::class,
            ]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sally Outreach',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Return the structured email envelope',
                'output_format' => 'json',
            ]);

        $outbound = null;

        try {
            $outbound = new AgentReachOutOnChannelAction(
                lead: $lead,
                agent: $agent,
                channelType: 'email',
                recipient: 'alan@kanvas.dev',
            )->execute();
        } catch (Throwable) {
            // Persistence + the faked mail dispatch run before any post-send hook.
        }

        $this->assertNotNull($outbound);
        $this->assertFalse($outbound->isLocked(), 'Draft must not be locked when approval mode is off');
        Notification::assertSentOnDemand(Blank::class);
    }
}
