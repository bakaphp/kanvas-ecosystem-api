<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CaptureConversationLeadTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CaptureConversationLeadToolTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $appModel;
    protected Companies $company;
    protected Users $user;
    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appModel = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($this->appModel->getId())->create();
        $this->agent = Agent::factory()
            ->withAppId($this->appModel->getId())
            ->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);
    }

    /**
     * The bug: a Slack DM channel is keyed per (agent, user) and its session UUID is derived from
     * the channel slug, so it is identical for every lead the prospect ever generates. Capturing a
     * new lead must NOT drag that shared DM's prior turns onto the new lead's channel.
     */
    public function testChannelDerivedSessionDoesNotBleedPriorMessagesIntoNewLead(): void
    {
        $channel = $this->createSlackStyleChannel();
        // Real Slack sessions key the UUID off the channel slug — same UUID across every lead.
        $sessionUuid = SessionChannelService::buildChannelSessionUuid($channel, $this->appModel, $this->company);
        $session = $this->createSession($channel, $sessionUuid);

        $priorMessage = $this->seedChannelMessage($channel, $sessionUuid, 'Prior prospect trade-in details');

        $result = new CaptureConversationLeadTool($this->appModel, $this->company, $this->user, $session)->__invoke(
            title: 'New prospect - Acme',
            firstname: 'Newman',
            email: 'lead-' . Str::uuid()->toString() . '@example.com',
        );

        $this->assertArrayHasKey('lead_id', $result, 'Lead creation must succeed: ' . json_encode($result));

        $leadChannel = $this->leadChannelFor((int) $result['lead_id']);
        $this->assertLeadChannelMissing($leadChannel, $priorMessage);
        $this->assertSame(0, $leadChannel->messages()->count(), 'A channel-derived session must seed the lead with a clean slate');
    }

    /**
     * A genuinely per-conversation session (anonymous chat, random UUID that isolates it from other
     * conversations on the same channel) SHOULD still backfill its own turns onto the new lead — the
     * fix only suppresses the copy when the UUID cannot isolate the conversation.
     */
    public function testIsolatedSessionStillBackfillsItsOwnMessages(): void
    {
        $channel = $this->createSlackStyleChannel();
        // Not derived from the slug → this UUID uniquely identifies one conversation.
        $sessionUuid = Str::uuid()->toString();
        $session = $this->createSession($channel, $sessionUuid);

        $ownMessage = $this->seedChannelMessage($channel, $sessionUuid, 'This prospect asked for a demo');

        $result = new CaptureConversationLeadTool($this->appModel, $this->company, $this->user, $session)->__invoke(
            title: 'Anon prospect',
            firstname: 'Ana',
            email: 'lead-' . Str::uuid()->toString() . '@example.com',
        );

        $this->assertArrayHasKey('lead_id', $result, 'Lead creation must succeed: ' . json_encode($result));

        $leadChannel = $this->leadChannelFor((int) $result['lead_id']);
        $this->assertTrue(
            $leadChannel->messages()->where('messages.id', $ownMessage->getId())->exists(),
            'An isolated session must still backfill its own conversation onto the new lead',
        );
    }

    private function createSlackStyleChannel(): Channel
    {
        return new CreateChannelAction(
            new ChannelData(
                apps: $this->appModel,
                companies: $this->company,
                users: $this->user,
                entity_id: $this->user->getId(),
                entity_namespace: Users::class,
                name: 'Slack DM',
                description: 'Slack conversation with ' . $this->agent->name,
                slug: SessionChannelService::createChannelSlug('slack', 't0bc-' . Str::random(6)),
            ),
        )->execute();
    }

    private function createSession(Channel $channel, string $uuid): Session
    {
        return Session::create([
            'uuid' => $uuid,
            'canal_id' => $channel->slug,
            'apps_id' => $this->appModel->getId(),
            'companies_id' => $this->company->getId(),
            'agents_id' => $this->agent->getId(),
            'channel_id' => $channel->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $this->user->getId(),
            'user' => ['id' => $this->user->getId(), 'name' => $this->user->displayname, 'email' => $this->user->email],
            'content' => [],
        ]);
    }

    private function seedChannelMessage(Channel $channel, string $sessionUuid, string $content): Message
    {
        $payload = new AiChatMessagePayload(
            content: $content,
            from_me: true,
            from_ia: true,
            session_id: $sessionUuid,
            agent_id: $this->agent->getId(),
        );

        $message = new CreateMessageAction(
            MessageInput::from([
                'app' => $this->appModel,
                'company' => $this->company,
                'user' => $this->user,
                'type' => MessageTypeService::getOrCreate($this->appModel, 'ai-chat'),
                'message' => $payload->toArray(),
                'is_public' => 1,
            ]),
            SystemModulesRepository::getByModelName(Users::class, $this->appModel),
            $this->user->getId(),
        )->execute();

        $channel->addMessage($message);

        return $message;
    }

    private function leadChannelFor(int $leadId): Channel
    {
        /** @var Lead $lead */
        $lead = Lead::getById($leadId, $this->appModel);

        return new LeadChannelService()->findOrCreateForLead($lead, $lead->app, $lead->company, $this->user);
    }

    private function assertLeadChannelMissing(Channel $leadChannel, Message $message): void
    {
        $this->assertFalse(
            $leadChannel->messages()->where('messages.id', $message->getId())->exists(),
            'Prior lead messages from the shared channel must not appear on the new lead channel',
        );
    }
}
