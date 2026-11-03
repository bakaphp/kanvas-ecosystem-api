<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Webhooks\EmailDeliveryWebhookJob;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Events\MessageDeliveryStatusEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\MessageAttempt;
use Kanvas\Social\Messages\Models\MessageDeliveryEvent;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class EmailDeliveryWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;
    private ReceiverWebhook $receiver;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->email = 'prospect-' . Str::lower(Str::random(10)) . '@example.com';

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => EmailDeliveryWebhookJob::class],
            ['name' => 'EmailDeliveryWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->user->getId())
            ->company($this->company->getId())
            ->create(['action_id' => $action->getId()]);
    }

    public function testDeliveredMarksTheAttemptAndTheMessageAndPushesIt(): void
    {
        Event::fake([MessageDeliveryStatusEvent::class]);
        $message = $this->createMessage();
        $this->attachToChannel($message);
        $attempt = $this->attemptFor($message);

        $result = $this->deliver($this->event('delivered', $attempt->provider_message_id));

        $this->assertSame('delivered', $result['status']);
        $attempt->refresh();
        $this->assertSame('delivered', $attempt->current_status);
        $this->assertNotNull($attempt->terminal_at);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('mailgun', $delivery['provider']);
        $this->assertSame('delivered', $delivery['status']);
        $this->assertSame($attempt->provider_message_id, $delivery['message_sid']);
        $this->assertNull($delivery['error_code']);

        Event::assertDispatched(
            MessageDeliveryStatusEvent::class,
            fn (MessageDeliveryStatusEvent $event): bool => $event->delivery['status'] === 'delivered'
                && $event->delivery['provider'] === 'mailgun'
        );
    }

    public function testAPermanentFailureMarksTheAddressBouncedAndKeepsTheSmtpCode(): void
    {
        $people = $this->seedPeople();
        $message = $this->createMessage();
        $attempt = $this->attemptFor($message);

        $this->deliver($this->event('failed', $attempt->provider_message_id, [
            'severity' => 'permanent',
            'reason' => 'bounce',
            'delivery-status' => ['code' => 550, 'description' => 'No such user'],
        ]));

        $attempt->refresh();
        $this->assertSame('failed', $attempt->current_status);
        $this->assertSame(550, (int) $attempt->last_error_code);
        $this->assertSame('No such user', $attempt->last_error_message);
        $this->assertSame('hard_bounce', $attempt->classification);
        $this->assertSame('suppress_email', $attempt->remediation_action);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('failed', $delivery['status']);
        $this->assertSame('550', $delivery['error_code']);
        $this->assertSame('No such user', $delivery['error_message']);

        $this->assertSame(ContactValidationStatusEnum::HARD_BOUNCE, $this->emailContact($people)->validation_status);
        $this->assertFalse((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    public function testATemporaryFailureAfterDeliveryDoesNotUndoIt(): void
    {
        $people = $this->seedPeople();
        $message = $this->createMessage();
        $attempt = $this->attemptFor($message);

        $this->deliver($this->event('delivered', $attempt->provider_message_id));
        $this->deliver($this->event('failed', $attempt->provider_message_id, [
            'severity' => 'temporary',
            'delivery-status' => ['code' => 421, 'message' => 'Try again later'],
        ]));

        $this->assertSame('delivered', $attempt->fresh()->current_status);
        $this->assertSame('delivered', $message->fresh()->getMessage()['delivery']['status']);
        $this->assertSame(ContactValidationStatusEnum::VALID, $this->emailContact($people)->validation_status);
        $this->assertSame(2, MessageDeliveryEvent::query()->where('attempt_id', $attempt->getId())->count());
    }

    public function testAReplayedEventIsRecordedOnce(): void
    {
        $attempt = $this->attemptFor($this->createMessage());
        $event = $this->event('delivered', $attempt->provider_message_id);

        $this->deliver($event);
        $result = $this->deliver($event);

        $this->assertSame('Mailgun event already recorded', $result['message']);
        $this->assertSame(1, MessageDeliveryEvent::query()->where('attempt_id', $attempt->getId())->count());
    }

    public function testASpamComplaintIsAStopRequestForThePerson(): void
    {
        $people = $this->seedPeople();
        $attempt = $this->attemptFor($this->createMessage());

        $this->deliver($this->event('delivered', $attempt->provider_message_id));
        $this->deliver($this->event('complained', $attempt->provider_message_id));

        $attempt->refresh();
        $this->assertSame('delivered', $attempt->current_status);
        $this->assertSame('complained', $attempt->classification);
        $this->assertTrue((bool) $people->fresh()->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertSame(1, (int) $this->emailContact($people)->is_opt_out);
    }

    public function testAnEmailWeNeverSentIsIgnored(): void
    {
        $result = $this->deliver($this->event('delivered', 'unknown-' . Str::random(8) . '@mg.example.com'));

        $this->assertSame('No outbound email on record for this Message-Id, ignored', $result['message']);
    }

    public function testOpensAreLeftToTheOpenedReceiver(): void
    {
        $attempt = $this->attemptFor($this->createMessage());

        $result = $this->deliver($this->event('opened', $attempt->provider_message_id));

        $this->assertSame('Event opened is not a delivery event, ignored', $result['message']);
        $this->assertSame('accepted', $attempt->fresh()->current_status);
    }

    private function event(string $event, string $messageId, array $extra = []): array
    {
        return [
            'signature' => ['token' => 'token', 'timestamp' => (string) time(), 'signature' => 'signature'],
            'event-data' => array_merge([
                'id' => 'evt-' . Str::random(16),
                'event' => $event,
                'timestamp' => microtime(true),
                'recipient' => $this->email,
                'message' => ['headers' => ['message-id' => $messageId]],
            ], $extra),
        ];
    }

    private function attemptFor(Message $message): MessageAttempt
    {
        return MessageAttempt::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'message_id' => $message->getId(),
            'provider' => 'mailgun',
            'provider_message_id' => Str::random(20) . '@mg.example.com',
            'to_address' => $this->email,
            'current_status' => 'accepted',
        ]);
    }

    private function seedPeople(): People
    {
        $people = PeopleFactory::new()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create();
        $people->addEmail(strtoupper($this->email));

        return $people;
    }

    private function emailContact(People $people): Contact
    {
        return $people->contacts()
            ->whereIn('contacts_types_id', Contact::EMAIL_TYPES)
            ->whereRaw('LOWER(value) = ?', [$this->email])
            ->firstOrFail();
    }

    private function createMessage(): Message
    {
        return Message::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'users_id' => $this->user->getId(),
                'message' => ['content' => 'Here is your quote'],
            ]);
    }

    private function attachToChannel(Message $message): void
    {
        SystemModules::firstOrCreate(
            ['model_name' => People::class, 'apps_id' => $this->kanvasApp->getId()],
            ['name' => 'People', 'slug' => 'people', 'description' => 'People system module']
        );
        $people = $this->seedPeople();

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $this->kanvasApp,
                'companies' => $this->company,
                'users' => $this->user,
                'entity_id' => $people->getId(),
                'entity_namespace' => People::class,
                'name' => 'Email ' . $people->getId(),
                'slug' => 'email-delivery-' . $people->getId(),
            ])
        )->execute();
        $channel->messages()->attach($message->getId(), ['users_id' => $this->user->getId()]);
    }

    private function deliver(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();
        $result = new EmailDeliveryWebhookJob($webhookRequest)->handle();

        if (! is_array($result)) {
            $this->fail('Job failed: ' . json_encode($webhookRequest->refresh()->exception['message'] ?? 'unknown'));
        }

        return $result;
    }
}
