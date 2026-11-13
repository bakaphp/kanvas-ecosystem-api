<?php

declare(strict_types=1);

namespace Tests\Communication\Channels\Email\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\FileEmailDeliveryAction;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions\SendAgentEmailAction;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Tests\TestCase;

/**
 * Email on the company SMTP identity (agents without a mailbox, lead sends) is filed in the ledger under
 * a Message-ID we fix ourselves, so Mailgun's events for it find it the way they find a mailbox send.
 */
final class SmtpEmailDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $this->smtpHost('smtp.mailgun.org');

        // The local database may lack the templates these sends render; their content is not under test.
        foreach (['first-time-agent-engagement', 'agent-email-response'] as $template) {
            try {
                TemplatesRepository::getByName($template, app(Apps::class), auth()->user()->getCurrentCompany());
            } catch (ModelNotFoundException) {
                new CreateTemplateAction(
                    TemplateInput::from([
                        'app' => app(Apps::class),
                        'name' => $template,
                        'template' => '<p>Reply</p>',
                    ])
                )->execute();
            }
        }
    }

    public function testALeadSendOnMailgunSmtpIsFiledUnderTheMessageIdItCarries(): void
    {
        $lead = $this->leadWithEmail('prospect@example.com');

        $result = new SendMessageToLeadAction($lead)->execute(
            channel: LeadCommunicationChannelEnum::EMAIL->value,
            message: 'Here is the quote.',
            title: 'Your quote',
            to: 'prospect@example.com',
        );

        $this->assertSame('mailgun', $result['provider']);
        $delivery = Delivery::query()->where('uuid', $result['attempt_uuid'])->firstOrFail();

        $this->assertSame($result['provider_message_id'], $delivery->external_id);
        $this->assertSame($delivery->external_id, $this->sentMessageId());
        $this->assertSame('accepted', $delivery->status->value);
        $this->assertNotNull($delivery->accepted_at);
        $this->assertSame('prospect@example.com', $delivery->to_address);
        $this->assertSame($lead->people->getId(), (int) $delivery->request->people_id);
        $this->assertSame('Your quote', $delivery->request->content['subject']);

        $message = $this->createMessage();
        new StoreMessageSidAction($message)->execute($result);

        $this->assertSame($message->getId(), (int) $delivery->fresh()->message_id);
        $this->assertSame('accepted', $message->fresh()->getMessage()['delivery']['status']);
    }

    public function testAnSmtpServerThatIsNotMailgunFilesNothing(): void
    {
        $this->smtpHost('smtp.example.com');
        $lead = $this->leadWithEmail('prospect@example.com');

        $result = new SendMessageToLeadAction($lead)->execute(
            channel: LeadCommunicationChannelEnum::EMAIL->value,
            message: 'Here is the quote.',
            title: 'Your quote',
            to: 'prospect@example.com',
        );

        $this->assertArrayNotHasKey('provider_message_id', $result);
        $this->assertFalse(Delivery::query()->where('external_id', $this->sentMessageId())->exists());
    }

    public function testAnAgentWithoutAMailboxFilesItsReplyOnTheMessage(): void
    {
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['user_id' => auth()->user()->getId()]);
        $message = $this->createMessage([
            'content' => 'Thanks for writing back.',
            'chat_jid' => 'prospect@example.com',
            'subject' => 'Your quote',
            'agent_id' => $agent->getId(),
        ]);

        new SendAgentEmailAction($message)->execute();

        $delivery = Delivery::query()->where('message_id', $message->getId())->firstOrFail();
        $this->assertSame($delivery->external_id, $this->sentMessageId());
        $this->assertSame('accepted', $delivery->status->value);
        $this->assertEquals(['type' => 'agent', 'id' => $agent->getId()], $delivery->request->sender);
        $this->assertSame('accepted', $message->fresh()->getMessage()['delivery']['status']);
    }

    public function testAMailgunEventThatBeatTheQueueIsNotRolledBack(): void
    {
        $message = $this->createMessage();
        $notification = new Blank('agent-email-response', ['content' => 'hi'], ['mail'], $message);
        $delivery = new FileEmailDeliveryAction(
            app: $message->app,
            company: $message->company,
            sender: Sender::system(),
            to: 'prospect@example.com',
            content: Content::text('hi'),
            message: $message,
        )->sending($notification);

        $this->assertSame('sending', $delivery->status->value);
        $this->assertSame($delivery->external_id, $notification->getMessageId());

        new RecordDeliveryEventAction($delivery, DeliveryStatusEnum::DELIVERED, 'delivered', occurredAt: now())->execute();
        Notification::route('mail', 'prospect@example.com')->notify($notification);

        $this->assertSame('delivered', $delivery->fresh()->status->value);
    }

    private function smtpHost(string $host): void
    {
        config(['mail.mailers.smtp.host' => $host]);

        // A company or app SMTP host outranks the config default; this suite needs the default to win.
        $company = auth()->user()->getCurrentCompany();
        if ($company->get('smtp_host') || app(Apps::class)->get('smtp_host')) {
            $this->markTestSkipped('The shared company/app has its own SMTP host');
        }
    }

    private function sentMessageId(): string
    {
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last();
        $this->assertNotNull($sent, 'No email went out');

        // The Message-ID the mail carried: ours when one was fixed, the mailer's own otherwise.
        return $sent->getMessageId();
    }

    private function leadWithEmail(string $email): Lead
    {
        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $lead->people->contacts()
            ->whereIn('contacts_types_id', [
                ContactTypeEnum::PRIMARY_EMAIL->value,
                ContactTypeEnum::EMAIL->value,
                ContactTypeEnum::SECONDARY_EMAIL->value,
            ])
            ->delete();
        $lead->people->addEmail($email);

        return $lead;
    }

    private function createMessage(array $body = ['content' => 'Here is the quote.']): Message
    {
        $user = auth()->user();

        return Message::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'users_id' => $user->getId(),
                'message' => $body + ['marker' => Str::random(8)],
            ]);
    }
}
