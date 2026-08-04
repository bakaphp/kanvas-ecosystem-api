<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\RecordMessageAttemptAction;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Jobs\RetryMessageAttemptJob;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Connectors\Twilio\Models\MessageDeliveryEvent;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class ProcessTwilioMessageStatusWebhookJobTest extends TestCase
{
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
        $attempt = MessageAttempt::query()->where('message_sid', 'SM123')->firstOrFail();
        $this->assertSame('delivered', $attempt->current_status);
        $this->assertNotNull($attempt->terminal_at);
        $this->assertSame(1, MessageDeliveryEvent::query()->where('attempt_id', $attempt->getId())->count());
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

    public function testSchedulesOnlyOneDelayedRetryForFirstCarrierFailure(): void
    {
        Queue::fake();

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
            ->create(['users_id' => $user->getId()]);
        $parent->set(CustomFieldEnum::MESSAGE_SID->value, 'SM-retry-source');
        new RecordMessageAttemptAction($parent)->execute([
            'attempt_uuid' => 'c31ec72a-84eb-4887-8575-7c45a3049aa9',
            'messages' => [[
                'sid' => 'SM-retry-source',
                'status' => 'queued',
                'to' => '+18095551234',
            ]],
        ]);

        $receiver = $this->createReceiver($app, $company->getId(), $user);
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => [
                'MessageSid' => 'SM-retry-source',
                'MessageStatus' => 'undelivered',
                'ErrorCode' => '30003',
                'To' => '+18095551234',
            ],
        ]);
        $job = new ProcessTwilioMessageStatusWebhookJob($webhookCall);

        $job->execute();
        $job->execute();

        $attempt = MessageAttempt::query()->where('message_sid', 'SM-retry-source')->firstOrFail();
        $this->assertSame('retry_scheduled', $attempt->remediation_action);
        Queue::assertPushed(RetryMessageAttemptJob::class, 1);
    }

    public function testSuppressesSmsWhenControlledRetryAlsoFails(): void
    {
        Queue::fake();

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
            ->create(['users_id' => $user->getId()]);
        $parent->set(CustomFieldEnum::MESSAGE_SID->value, 'SM-retry-failed');
        new RecordMessageAttemptAction($parent)->execute([
            'attempt_uuid' => 'e188b44e-81b8-49ce-b451-cc14c373571a',
            'retry_number' => 1,
            'parent_attempt_id' => 123,
            'messages' => [[
                'sid' => 'SM-retry-failed',
                'status' => 'queued',
                'to' => '+18095551234',
            ]],
        ]);

        $receiver = $this->createReceiver($app, $company->getId(), $user);
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'url' => $receiver->getUrl(),
            'headers' => [],
            'payload' => [
                'MessageSid' => 'SM-retry-failed',
                'MessageStatus' => 'undelivered',
                'ErrorCode' => '30005',
                'To' => '+18095551234',
            ],
        ]);

        new ProcessTwilioMessageStatusWebhookJob($webhookCall)->execute();

        $attempt = MessageAttempt::query()->where('message_sid', 'SM-retry-failed')->firstOrFail();
        $this->assertSame('suppress_sms', $attempt->remediation_action);
        Queue::assertNotPushed(RetryMessageAttemptJob::class);
    }

    public function testCancelsDelayedRetryWhenConversationHasNewerActivity(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $messageType = MessageType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'languages_id' => 1,
                'verb' => 'twilio-sms',
            ],
            ['name' => 'Twilio SMS'],
        );
        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'users_id' => $user->getId(),
                'message' => ['content' => 'Old message'],
                'created_at' => now()->subMinutes(2),
            ]);
        $attempt = MessageAttempt::query()->create([
            'uuid' => '17f61b87-9127-4d75-b463-acb74225c5ee',
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'message_id' => $message->getId(),
            'lead_id' => $lead->getId(),
            'message_sid' => 'SM-old-message',
            'to_number' => '+18095551234',
            'current_status' => 'undelivered',
            'remediation_action' => 'retry_scheduled',
            'retry_number' => 0,
            'created_at' => now()->subMinutes(2),
        ]);
        MessageAttempt::query()->create([
            'uuid' => 'aebbf902-6830-487b-9220-71bc7a047b22',
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'lead_id' => $lead->getId(),
            'message_sid' => 'SM-new-message',
            'to_number' => '+18095551234',
            'current_status' => 'sent',
            'retry_number' => 0,
            'created_at' => now(),
        ]);

        new RetryMessageAttemptJob($attempt->getId())->handle();

        $this->assertSame(
            'retry_canceled_superseded',
            $attempt->fresh()->remediation_action,
        );
    }

    private function createReceiver(Apps $app, int $companyId, Users $user): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessTwilioMessageStatusWebhookJob::class],
            ['name' => 'ProcessTwilioMessageStatusWebhookJob'],
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
