<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Outreach\PersistOutboundMessageAction;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
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

    public function testAnSmsSendStoresTheSidAndFiresMessageCreated(): void
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
        $this->assertSame($sid, $message->get(CustomFieldEnum::MESSAGE_SID->value));
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
