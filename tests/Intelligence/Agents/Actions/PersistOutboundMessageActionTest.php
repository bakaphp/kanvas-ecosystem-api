<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\Stubs\Workflow\RecordingActivity;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncRules;

/**
 * A tool send is persisted after delivery, and a Message rule (a CRM note push) must see it the way
 * it sees a reach-out or a channel reply: once, with the lead attached, on the outbound row only.
 */
class PersistOutboundMessageActionTest extends TestCase
{
    use BuildsSyncRules;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'social', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        RecordingActivity::reset();
        $this->createSyncRule(
            app: $this->kanvasApp,
            event: WorkflowEnum::CREATED->value,
            modelClass: Message::class,
            activityClass: RecordingActivity::class,
            company: $this->company,
            conditions: [["message.hasTag(['agent-tool-delivery'])", '==', 'true']],
        );
    }

    private function onChannel(Message $message, string $slug): bool
    {
        return Channel::query()
            ->where('slug', $slug)
            ->whereHas('messages', fn ($messages) => $messages->whereKey($message->getId()))
            ->exists();
    }

    private function makeLead(): Lead
    {
        return Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();
    }

    public function testAnEmailSendFiresMessageCreatedOnTheOutbound(): void
    {
        $lead = $this->makeLead();

        ['message' => $message, 'channel' => $channel] = new PersistOutboundMessageAction(
            entity: $lead,
            user: $this->user,
            channelType: LeadCommunicationChannelEnum::EMAIL->value,
            recipient: 'prospect@example.com',
            content: 'Here is the quote you asked for.',
            subject: 'Your quote',
        )->execute(['channel' => 'email', 'to' => 'prospect@example.com']);

        $this->assertSame([$message->getId()], RecordingActivity::entityIds());
        $this->assertSame('created', RecordingActivity::$calls[0]['params']['currentEventTypeName']);
        $this->assertSame($lead->getId(), $message->entity(Lead::class)?->getId());
        $this->assertTrue($channel->messages()->whereKey($message->getId())->exists());
    }

    public function testAnSmsSendFiresMessageCreated(): void
    {
        $lead = $this->makeLead();
        $sid = 'SM' . uniqid();

        ['message' => $message] = new PersistOutboundMessageAction(
            entity: $lead,
            user: $this->user,
            channelType: LeadCommunicationChannelEnum::SMS->value,
            recipient: '+14047907130',
            content: 'Sally here, glad to hear that!',
        )->execute(['messages' => [['sid' => $sid, 'status' => 'queued']]]);

        $this->assertSame([$message->getId()], RecordingActivity::entityIds());
    }

    /**
     * The customer's conversation belongs to the person. A text a tool sent to their lead is filed
     * in that same channel, found by its slug alone, and no lead-owned twin of it is made.
     */
    public function testASendThatNamesItsConversationIsFiledOnThePersonsChannelForALeadToo(): void
    {
        $lead = $this->makeLead();
        $slug = 'twilio-' . uniqid();
        $conversation = Channel::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => '+14047907131',
            'description' => 'Conversation with +14047907131',
            'slug' => $slug,
            'entity_namespace' => People::class,
            'entity_id' => $lead->people->getId(),
        ]);

        ['channel' => $channel, 'message' => $message] = new PersistOutboundMessageAction(
            entity: $lead,
            user: $this->user,
            channelType: LeadCommunicationChannelEnum::SMS->value,
            recipient: '+14047907131',
            content: 'Your car is ready',
        )->execute(['messages' => [['sid' => 'SM' . uniqid()]], 'channel_slug' => $slug]);

        $this->assertTrue($channel->is($conversation));
        $this->assertSame(People::class, $channel->entity_namespace, 'the conversation stays the person\'s');
        $this->assertSame(1, Channel::query()->where('slug', $slug)->count(), 'no second channel for the lead');
        $this->assertNotNull($message->entity(Lead::class), 'the lead still has the message');
        $this->assertTrue($this->onChannel($message, 'lead-channel-' . $lead->getId()), 'on the lead\'s history');
        $this->assertTrue(
            $this->onChannel($message, 'people-channel-' . $lead->people->getId()),
            'on the person\'s whole conversation'
        );
    }

    public function testAWhatsAppSendIsNotFiledAsAFailedText(): void
    {
        $lead = $this->makeLead();

        // A WaSender answer, as a WhatsApp campaign hands it over: no `channel`, no SID.
        ['message' => $message] = new PersistOutboundMessageAction(
            entity: $lead,
            user: $this->user,
            channelType: LeadCommunicationChannelEnum::WHATSAPP->value,
            recipient: '+14047907130',
            content: 'Sally here, glad to hear that!',
        )->execute(['success' => true, 'data' => ['msgId' => 123]]);

        $this->assertFalse(MessageAttempt::query()->where('message_id', $message->getId())->exists());
        $this->assertArrayNotHasKey('delivery', $message->fresh()->getMessage());
    }

    public function testTheSendEmailToolFiresMessageCreatedOnTheEmailAndNotOnItsNote(): void
    {
        Notification::fake();
        $lead = $this->makeLead();

        $result = new SendEmailTool()
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->__invoke(
                lead_id: $lead->getId(),
                subject: 'Your quote',
                body: 'Here is the quote you asked for.',
            );

        $this->assertSame('success', $result['status']);
        $this->assertSame([$result['message_id']], RecordingActivity::entityIds());
    }
}
