<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
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
            ->where('slug', 'twilio-status-sm123-delivered')
            ->get();

        $this->assertCount(1, $children);
        $this->assertSame('delivered', $children->first()->message['content']);
        $this->assertSame($parent->uuid, $children->first()->parent_unique_id);
        $this->assertSame('Twilio message status recorded', $firstResult['message']);
        $this->assertSame('Twilio message status already recorded', $secondResult['message']);
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
