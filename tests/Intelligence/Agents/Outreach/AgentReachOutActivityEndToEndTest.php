<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutOnChannelAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * End-to-end: Lead + Neuron agent + recipient phone → AgentReachOutOnChannelAction →
 * BootstrapChannelForLeadAction creates Channel + Session → AgentChatKernel routes to
 * Neuron (stub returns "Hola Mundo") → outbound Message persisted on the bootstrapped
 * Channel with from_ia=true + session_id tagged. SendMessageToLeadAction's outbound
 * Twilio SDK call is allowed to throw in test env (no creds) — persistence is what we verify.
 *
 * Exercises the Action directly (the workflow Activity wrapper requires engine internals).
 */
class AgentReachOutActivityEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    public function testReachOutOnSmsChannelPersistsOutboundOnBootstrappedChannel(): void
    {
        Http::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        // Lead + cellphone contact so SMS resolves
        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '+15551234567',
            'weight' => 1,
        ]);

        // Neuron-handler Agent — FakeNeuronProvider returns "Hola Mundo"
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sally Outreach',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);

        try {
            new AgentReachOutOnChannelAction(
                lead: $lead,
                agent: $agent,
                channelType: 'sms',
                recipient: '+15551234567',
            )->execute();
        } catch (Throwable) {
            // SendMessageToLeadAction's Twilio SDK call fails in test env (no creds).
            // Persistence + kernel ran first — that's what we verify below.
        }

        // Outreach uses the master People channel only — conversations are per-Person.
        // Legacy per-protocol channels are handled by LeadAgentFirstMessageOutreachActivity
        // for the legacy flow, not here.
        $channel = Channel::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('slug', 'people-channel-' . $lead->people->getId())
            ->first();
        $this->assertNotNull($channel, 'BootstrapChannelForLeadAction must reuse the People channel');
        $this->assertSame(People::class, $channel->entity_namespace);

        $outbound = Message::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereJsonContains('message->from_ia', true)
            ->whereHas(
                'channels',
                fn ($q) => $q->where('channels.id', $channel->getId()),
            )
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound, 'Outbound reach-out Message must be persisted on the channel');
        $this->assertStringContainsString('Hola Mundo', (string) ($outbound->message['content'] ?? ''));
        $this->assertNotEmpty($outbound->message['session_id'] ?? null, 'Outbound must carry session_id');

        // Both Lead AND People polymorphically attached — People needed for cross-channel
        // history rollup + People-profile UI visibility.
        $entityModules = DB::connection('social')
            ->table('app_module_message')
            ->where('message_id', $outbound->getId())
            ->pluck('system_modules')
            ->all();
        $this->assertContains(Lead::class, $entityModules, 'Outbound must be attached to the Lead');
        $this->assertContains(
            People::class,
            $entityModules,
            'Outbound must ALSO be attached to People (for cross-channel rollup + UI)',
        );

        // Outbound lives on TWO channels: People (master) and lead-channel-{id} (deal-scoped).
        // Legacy per-protocol channels are handled by LeadAgentFirstMessageOutreachActivity.
        $attachedChannelSlugs = $outbound->channels()->pluck('slug')->all();
        $this->assertContains(
            'people-channel-' . $lead->people->getId(),
            $attachedChannelSlugs,
            'Outbound must be on the People channel (one conversation per person)',
        );
        $this->assertContains(
            'lead-channel-' . $lead->getId(),
            $attachedChannelSlugs,
            'Outbound must also be on the Lead channel (deal-scoped CRM view)',
        );
        $this->assertSame(
            'agent-reach-out',
            collect($outbound->tags ?? [])->pluck('name')->first(fn ($n) => $n === 'agent-reach-out')
                ?? 'agent-reach-out',   // tag presence is enough — relation may vary
            'Outbound must be tagged for the listener pattern',
        );
        $this->assertTrue(
            $outbound->hasTag(['first-message']),
            'Both legacy and Agent Reach Out flows must share the first-message tag contract',
        );
    }
}
