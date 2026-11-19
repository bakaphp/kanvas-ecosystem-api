<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\RecordMessageAttemptAction;
use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Connectors\Twilio\Models\MessageDeliveryEvent;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Events\MessageDeliveryStatusEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Twilio\Rest\Client as TwilioClient;

final class ProcessTwilioMessageStatusWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    public function testCreatesIdempotentStatusChildForMessageSid(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $messageType = MessageType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'languages_id' => 1,
                'verb' => 'twilio-sms',
            ],
            ['name' => 'Twilio SMS'],
        );

        $parent = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'users_id' => $user->getId(),
            ]);
        $parent->set(CustomFieldEnum::MESSAGE_SID->value, 'SM123');

        $receiver = $this->createReceiver($app, $company->getId(), $user);
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => [
                'MessageSid' => 'SM123',
                'MessageStatus' => 'delivered',
            ],
        ]);

        $job = new ProcessTwilioMessageStatusWebhookJob($webhookCall);
        $firstResult = $job->execute();
        $secondResult = $job->execute();

        $children = Message::query()
            ->where('parent_id', $parent->getId())
            ->where('slug', 'like', 'twilio-status-sm123-delivered-%')
            ->get();

        $this->assertCount(1, $children);
        $this->assertSame('delivered', $children->first()->message['content']);
        $this->assertSame($parent->uuid, $children->first()->parent_unique_id);
        $this->assertSame('Twilio message status recorded', $firstResult['message']);
        $this->assertSame('Twilio message status already recorded', $secondResult['message']);
        $this->assertSame('delivered', $parent->get(CustomFieldEnum::CURRENT_STATUS->value));
        $delivery = $parent->fresh()->getMessage()['delivery'];
        $this->assertSame('delivered', $delivery['status']);
        $this->assertSame('SM123', $delivery['message_sid']);
        $this->assertNull($delivery['error_code']);
        $attempt = MessageAttempt::query()->where('message_sid', 'SM123')->firstOrFail();
        $this->assertSame('delivered', $attempt->current_status);
        $this->assertNotNull($attempt->terminal_at);
        $this->assertSame(1, MessageDeliveryEvent::query()->where('attempt_id', $attempt->getId())->count());
    }

    public function testReroutesInboundTextThatReachedTheStatusReceiver(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $statusReceiver = $this->createReceiver($app, $company->getId(), $user);
        $inboundReceiver = $this->createReceiver(
            $app,
            $company->getId(),
            $user,
            ProcessTwilioWebhookJob::class,
        );
        $payload = $this->inboundTextPayload('SM-inbound-on-status-receiver');
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $statusReceiver->getId(),
            'url' => $statusReceiver->getUrl(),
            'headers' => ['x-twilio-signature' => ['abc']],
            'payload' => $payload,
            'raw_payload' => http_build_query($payload),
        ]);

        $result = new ProcessTwilioMessageStatusWebhookJob($webhookCall)->execute();

        $inboundCall = ReceiverWebhookCall::query()
            ->where('receiver_webhooks_id', $inboundReceiver->getId())
            ->firstOrFail();
        $this->assertSame($payload, $inboundCall->payload);
        $this->assertSame($webhookCall->raw_payload, $inboundCall->raw_payload);
        $this->assertSame($inboundCall->getId(), $result['inbound_call_id']);
        $this->assertSame($inboundReceiver->getId(), $result['inbound_receiver_id']);
        Queue::assertPushed(ProcessTwilioWebhookJob::class, 1);
        $this->assertSame(
            0,
            MessageAttempt::query()->where('message_sid', 'SM-inbound-on-status-receiver')->count(),
        );
    }

    public function testRejectsInboundTextWhenTheCompanyHasNoInboundReceiver(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $statusReceiver = $this->createReceiver($app, $company->getId(), $user);
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $statusReceiver->getId(),
            'url' => $statusReceiver->getUrl(),
            'headers' => [],
            'payload' => $this->inboundTextPayload('SM-inbound-no-receiver'),
        ]);

        $job = new ProcessTwilioMessageStatusWebhookJob($webhookCall);
        $result = $job->execute();

        $this->assertSame(422, $result['status']);
        $this->assertSame(422, $job->getFailedReturnHttpCode());
        Queue::assertNotPushed(ProcessTwilioWebhookJob::class);
        $this->assertSame(
            0,
            MessageAttempt::query()->where('message_sid', 'SM-inbound-no-receiver')->count(),
        );
    }

    public function testPersistsCallbackWhenParentMessageHasNotBeenLinkedYet(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $receiver = $this->createReceiver($app, $company->getId(), $user);
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => [
                'AccountSid' => 'AC123',
                'MessageSid' => 'SM-orphan-callback',
                'MessageStatus' => 'sent',
                'To' => '+18095551234',
            ],
        ]);

        $result = new ProcessTwilioMessageStatusWebhookJob($webhookCall)->execute();

        $attempt = MessageAttempt::query()
            ->where('message_sid', 'SM-orphan-callback')
            ->firstOrFail();
        $this->assertNull($attempt->message_id);
        $this->assertSame('sent', $attempt->current_status);
        $this->assertSame('Twilio status recorded; parent message not found', $result['message']);
        $this->assertSame(1, $attempt->events()->count());
    }

    #[DataProvider('carrierFailureCodes')]
    public function testMarksDestinationInvalidOnFirstCarrierFailureWithoutRetrying(string $errorCode): void
    {
        Queue::fake();
        Event::fake([MessageDeliveryStatusEvent::class]);

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $destination = '+1809' . random_int(1000000, 9999999);
        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();
        $contact = $people->addCellPhone($destination);
        $messageType = MessageType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'languages_id' => 1,
                'verb' => 'twilio-sms',
            ],
            ['name' => 'Twilio SMS'],
        );
        $parent = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create(['users_id' => $user->getId()]);
        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $user,
                'entity_id' => $people->getId(),
                'entity_namespace' => People::class,
                'name' => 'SMS ' . $people->getId(),
                'slug' => 'sms-carrier-failure-' . $people->getId(),
            ])
        )->execute();
        $channel->messages()->attach($parent->getId(), ['users_id' => $user->getId()]);
        $sid = 'SM-carrier-failure-' . $errorCode;
        $parent->set(CustomFieldEnum::MESSAGE_SID->value, $sid);
        new RecordMessageAttemptAction($parent)->execute([
            'messages' => [[
                'sid' => $sid,
                'status' => 'queued',
                'to' => $destination,
            ]],
        ]);

        $receiver = $this->createReceiver($app, $company->getId(), $user);
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => [
                'MessageSid' => $sid,
                'MessageStatus' => 'undelivered',
                'ErrorCode' => $errorCode,
                'To' => $destination,
            ],
        ]);

        new ProcessTwilioMessageStatusWebhookJob($webhookCall)->execute();

        $attempt = MessageAttempt::query()->where('message_sid', $sid)->firstOrFail();
        $this->assertSame('suppress_sms', $attempt->remediation_action);
        $this->assertSame('destination_unreachable', $attempt->classification);
        $this->assertSame(ContactValidationStatusEnum::INVALID, $contact->fresh()->validation_status);
        $delivery = $parent->fresh()->getMessage()['delivery'];
        $this->assertSame('undelivered', $delivery['status']);
        $this->assertSame($errorCode, $delivery['error_code']);
        $this->assertSame($sid, $delivery['message_sid']);
        Event::assertDispatched(
            MessageDeliveryStatusEvent::class,
            fn (MessageDeliveryStatusEvent $event): bool => $event->channel->is($channel)
                && $event->delivery['status'] === 'undelivered'
                && $event->delivery['error_code'] === $errorCode
        );
    }

    public static function carrierFailureCodes(): array
    {
        return [
            'unreachable handset' => ['30003'],
            'unknown destination' => ['30005'],
        ];
    }

    /**
     * The mark the carrier failure leaves is what the next text is refused on: before the Lookup,
     * so a dead number costs nothing more, and with the reason on the message that was not sent.
     */
    #[DataProvider('codesThatMarkANumberInvalid')]
    public function testANumberWhoseDeliveryFailedAcceptsNoMoreTexts(string $errorCode): void
    {
        Queue::fake();
        Event::fake([MessageDeliveryStatusEvent::class]);
        Notification::fake();
        config(['mail.mailers.smtp.host' => 'smtp.example.com']);

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $destination = '+1809' . random_int(1000000, 9999999);
        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $lead->people->addCellPhone($destination);
        $lead->people->addEmail('still-reachable-' . $lead->getId() . '@example.com');

        // A number nothing has failed on passes the destination guard.
        $this->textSender($lead)->guardSmsDestinationForTest($destination);

        $receiver = $this->createReceiver($app, $company->getId(), $user);
        new ProcessTwilioMessageStatusWebhookJob(ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => [
                'MessageSid' => 'SM-dead-number-' . $errorCode . '-' . $lead->getId(),
                'MessageStatus' => 'undelivered',
                'ErrorCode' => $errorCode,
                'To' => $destination,
            ],
        ]))->execute();

        $sender = $this->textSender($lead->fresh());
        $refused = $sender->execute(
            channel: 'sms',
            message: 'Second text',
            from: '+15555550100',
            to: $destination,
        );

        $this->assertFalse($refused['success']);
        $this->assertSame('validation_failed', $refused['classification']);
        $this->assertStringContainsString('is marked invalid; no further SMS is sent to it', $refused['error']);
        $this->assertSame([0, 0], [$sender->lookups, $sender->sends], 'neither the Lookup nor the Messages API is called');

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType(MessageType::firstOrCreate(
                ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'sms'],
                ['name' => 'Sms'],
            ))
            ->create(['users_id' => $user->getId(), 'message' => ['content' => 'Second text']]);
        new StoreMessageSidAction($message)->execute($refused);

        $delivery = $message->fresh()->getMessage()['delivery'];
        $this->assertSame('failed', $delivery['status']);
        $this->assertSame($refused['error'], $delivery['error_message']);

        $email = $this->textSender($lead->fresh())->execute('email', 'Still reachable by email', title: 'Hello');
        $this->assertArrayNotHasKey('error', $email, 'a dead phone number does not stop an email');
    }

    public static function codesThatMarkANumberInvalid(): array
    {
        return [
            'unreachable handset' => ['30003'],
            'unknown destination' => ['30005'],
            'landline or unreachable carrier' => ['30006'],
        ];
    }

    /**
     * The real lead send up to the two Twilio calls, which are counted instead of made.
     */
    private function textSender(Lead $lead): SendMessageToLeadAction
    {
        return new class ($lead) extends SendMessageToLeadAction {
            public int $lookups = 0;

            public int $sends = 0;

            #[Override]
            protected function validateTwilioSenderRoute(string $from): array
            {
                return ['from' => $from];
            }

            public function guardSmsDestinationForTest(string $cellphone): void
            {
                $this->guardSmsDestination($cellphone, null);
            }

            #[Override]
            protected function lookupPhoneNumber(TwilioClient $client, string $cellphone): string
            {
                $this->lookups++;

                return $cellphone;
            }

            #[Override]
            protected function sendTwilioMessages(
                TwilioClient $client,
                string $cellphone,
                array $route,
                string $fullMessage,
                array $mediaUrls,
            ): array {
                $this->sends++;

                return ['channel' => 'sms', 'messages' => []];
            }
        };
    }

    private function inboundTextPayload(string $sid): array
    {
        return [
            'To' => '+12295550100',
            'Body' => 'Hi how are you?',
            'From' => '+14045550199',
            'SmsSid' => $sid,
            'NumMedia' => '0',
            'SmsStatus' => 'received',
            'AccountSid' => 'AC123',
            'MessageSid' => $sid,
            'SmsMessageSid' => $sid,
            'MessagingServiceSid' => 'MG123',
        ];
    }

    private function createReceiver(
        Apps $app,
        int $companyId,
        Users $user,
        string $jobClass = ProcessTwilioMessageStatusWebhookJob::class,
    ): ReceiverWebhook {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => $jobClass],
            ['name' => class_basename($jobClass)],
        );

        return ReceiverWebhook::factory()
            ->app($app->getId())
            ->company($companyId)
            ->user($user->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
                'is_active' => true,
            ]);
    }
}
