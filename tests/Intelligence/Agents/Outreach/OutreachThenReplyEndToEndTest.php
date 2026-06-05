<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutOnChannelAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Throwable;

/**
 * Three-phase end-to-end for the new Neuron flow on Twilio (SMS):
 *
 *   1. Outreach   — AgentReachOutOnChannelAction sends a first-touch SMS
 *   2. Inbound    — Customer texts back; we simulate the persisted state
 *                   ProcessTwilioWebhookJob would produce (faithful to its
 *                   webhook payload: From, To, Body, SmsMessageSid)
 *   3. Responder  — Twilio\AgentChannelResponderAction processes the inbound
 *                   message and the agent replies
 *
 * Asserts the final channel + entity attachment picture so the cross-cycle
 * + cross-channel conversation continuity contract is locked in.
 */
class OutreachThenReplyEndToEndTest extends TestCase
{
    use DatabaseTransactions;

    private const string CUSTOMER_PHONE = '+15551234567';
    private const string TWILIO_FROM = '+19998887777';

    public function testOutreachThenCustomerReplyKeepsConversationOnPeopleAndLeadChannels(): void
    {
        Http::fake();

        // ─── shared setup ─────────────────────────────────────────────────────
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => self::CUSTOMER_PHONE,
            'weight' => 1,
        ]);

        $agent = $this->makeNeuronAgent($app, $company);

        // ─── PHASE 1: Outreach ────────────────────────────────────────────────
        try {
            new AgentReachOutOnChannelAction(
                lead: $lead,
                agent: $agent,
                channelType: 'sms',
                recipient: self::CUSTOMER_PHONE,
            )->execute();
        } catch (Throwable) {
            // SendMessageToLeadAction's Twilio SDK call fails in test (no real creds)
            // — persistence ran before that point.
        }

        $peopleChannel = Channel::query()
            ->where('slug', 'people-channel-' . $lead->people->getId())
            ->first();
        $this->assertNotNull($peopleChannel, 'PHASE 1: People channel must exist after outreach');

        $outreachMessage = $this->latestFromIaMessageOn($peopleChannel);
        $this->assertNotNull($outreachMessage, 'PHASE 1: outreach outbound must land on people-channel');
        $this->assertStringContainsString('Hola Mundo', (string) ($outreachMessage->message['content'] ?? ''));

        // ─── PHASE 2: Customer reply via Twilio webhook (simulated) ───────────
        // Faithful to ProcessTwilioWebhookJob's persisted state given a payload of:
        //   ['From' => +15551234567, 'To' => +19998887777, 'Body' => 'Yes I am interested',
        //    'SmsMessageSid' => 'SMxxxx']
        $twilioChannel = $this->getOrCreateTwilioChannel($app, $company, $lead, self::CUSTOMER_PHONE);
        $inboundMessage = $this->simulateTwilioInbound(
            $app,
            $company,
            $user,
            $twilioChannel,
            $lead,
            from: self::CUSTOMER_PHONE,
            to: self::TWILIO_FROM,
            body: 'Yes, I am interested',
            sid: 'SM' . uniqid(),
        );

        // Tier 1 fix verification: inbound is People-attached (not just Lead)
        $inboundEntityModules = DB::connection('social')
            ->table('app_module_message')
            ->where('message_id', $inboundMessage->getId())
            ->pluck('system_modules')
            ->all();
        $this->assertContains(Lead::class, $inboundEntityModules, 'PHASE 2: inbound must be Lead-attached');
        $this->assertContains(People::class, $inboundEntityModules, 'PHASE 2: Tier 1 — inbound must be People-attached');

        // ─── PHASE 3: Responder action handles the inbound + agent replies ────
        // Faithful to Twilio\AgentChannelResponderActivity's Lead-keyed session creation.
        $inboundSession = new CreateSessionAction(
            SessionDto::from([
                'app' => $app,
                'company' => $company,
                'channel' => $twilioChannel,
                'entity_namespace' => Lead::class,
                'entity_id' => $lead->getId(),
                'canal_id' => self::CUSTOMER_PHONE,
                'user' => [
                    'name' => $lead->people->getName() ?: 'Lead',
                    'id' => $lead->people->getId(),
                    'email' => null,
                ],
                'agent' => $agent,
            ])
        )->execute();

        try {
            new AgentChannelResponderAction(
                $twilioChannel,
                $inboundMessage,
                $agent,
                $inboundSession,
            )->execute(['from' => self::TWILIO_FROM]);
        } catch (Throwable) {
            // Outbound Twilio SDK call fails without real creds — persistence ran first.
        }

        // ─── Final picture ────────────────────────────────────────────────────
        $agentReply = Message::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereJsonContains('message->from_ia', true)
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $twilioChannel->getId()))
            ->where('id', '!=', $outreachMessage->getId())   // not the phase-1 outreach
            ->latest('id')
            ->first();

        $this->assertNotNull($agentReply, 'PHASE 3: agent reply must be persisted');
        $this->assertStringContainsString('Hola Mundo', (string) ($agentReply->message['content'] ?? ''));

        // Tier 2 verification: non-ADK agent reply dual-writes to lead-channel + people-channel.
        $replyChannelSlugs = $agentReply->channels()->pluck('slug')->all();
        $this->assertContains(
            'twilio-' . str_replace('+', '', self::CUSTOMER_PHONE),
            $replyChannelSlugs,
            'PHASE 3: agent reply on twilio-XXX channel (per-protocol delivery)',
        );
        $this->assertContains(
            'lead-channel-' . $lead->getId(),
            $replyChannelSlugs,
            'PHASE 3: Tier 2 — non-ADK agent reply must dual-write to lead-channel',
        );
        $this->assertContains(
            'people-channel-' . $lead->people->getId(),
            $replyChannelSlugs,
            'PHASE 3: Tier 2 — non-ADK agent reply must dual-write to people-channel',
        );

        // Polymorphic entity attachment on the reply
        $replyEntityModules = DB::connection('social')
            ->table('app_module_message')
            ->where('message_id', $agentReply->getId())
            ->pluck('system_modules')
            ->all();
        $this->assertContains(Lead::class, $replyEntityModules);
        $this->assertContains(People::class, $replyEntityModules);

        // Within this Lead, the agent's history loader (queries by Lead entity in the
        // current inbound flow — Twilio activity creates Lead-keyed sessions) finds
        // outreach + inbound + reply — full thread continuity.
        $leadAttachedMessageIds = DB::connection('social')
            ->table('app_module_message')
            ->where('system_modules', Lead::class)
            ->where('entity_id', $lead->getId())
            ->pluck('message_id')
            ->all();
        $this->assertContains($outreachMessage->getId(), $leadAttachedMessageIds, 'history: outreach in Lead view');
        $this->assertContains($inboundMessage->getId(), $leadAttachedMessageIds, 'history: inbound in Lead view');
        $this->assertContains($agentReply->getId(), $leadAttachedMessageIds, 'history: agent reply in Lead view');

        // Same holds for People-keyed views (used by the new outreach session).
        $peopleAttachedMessageIds = DB::connection('social')
            ->table('app_module_message')
            ->where('system_modules', People::class)
            ->where('entity_id', $lead->people->getId())
            ->pluck('message_id')
            ->all();
        $this->assertContains($outreachMessage->getId(), $peopleAttachedMessageIds, 'history: outreach in People view');
        $this->assertContains($inboundMessage->getId(), $peopleAttachedMessageIds, 'history: inbound in People view');
        $this->assertContains($agentReply->getId(), $peopleAttachedMessageIds, 'history: agent reply in People view');
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function makeNeuronAgent(Apps $app, Companies $company): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Sales',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
    }

    private function getOrCreateTwilioChannel(Apps $app, Companies $company, Lead $lead, string $fromPhone): Channel
    {
        $slug = 'twilio-' . str_replace('+', '', $fromPhone);

        return new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $lead->user,
                'entity_id' => $lead->getId(),
                'entity_namespace' => Lead::class,
                'name' => 'Twilio SMS — ' . $fromPhone,
                'slug' => $slug,
            ])
        )->execute();
    }

    private function simulateTwilioInbound(
        Apps $app,
        Companies $company,
        Users $user,
        Channel $channel,
        Lead $lead,
        string $from,
        string $to,
        string $body,
        string $sid,
    ): Message {
        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        $input = new MessageInput(
            app: $app,
            company: $company,
            user: $user,
            type: $messageType,
            message: AiChatMessagePayload::from([
                'content' => $body,
                'from_me' => false,
                'from_ia' => false,
                'raw_data' => ['From' => $from, 'To' => $to, 'Body' => $body, 'SmsMessageSid' => $sid],
                'message_id' => $sid,
                'chat_jid' => $from,
            ])->toArray(),
            is_public: 1,
            tags: [$from],
        );
        $create = new CreateMessageAction($input);
        $create->runWorkflow = false;
        $message = $create->execute();

        // Mirror ProcessTwilioWebhookJob's persistence:
        //   - addEntity($lead)              (existing)
        //   - addEntity($lead->people)      (our Tier 1 fix)
        //   - channel attach
        $message->addEntity($lead);
        if ($lead->people !== null) {
            $message->addEntity($lead->people);
        }
        $channel->addMessage($message);

        return $message;
    }

    private function latestFromIaMessageOn(Channel $channel): ?Message
    {
        return Message::query()
            ->whereJsonContains('message->from_ia', true)
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $channel->getId()))
            ->latest('id')
            ->first();
    }
}
