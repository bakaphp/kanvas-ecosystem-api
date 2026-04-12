<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\RespondIO;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Connectors\RespondIO\Webhooks\ProcessRespondIOWebhookJob;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class ProcessRespondIOWebhookJobTest extends TestCase
{
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::BEARER_TOKEN->value, 'test_token');

        LeadType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'name' => 'Warm',
            ],
            [
                'description' => 'Warm Lead Type',
                'is_active' => true,
                'uuid' => Str::uuid(),
            ]
        );

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessRespondIOWebhookJob::class],
            ['name' => 'ProcessRespondIOWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testProcessIncomingMessage(): void
    {
        $phone = '+60123456789';

        $payload = [
            'sender' => [
                'type' => 'message',
                'source' => 'contact',
            ],
            'channel' => [
                'id' => 1,
                'meta' => '{}',
                'name' => 'WhatsApp',
                'source' => 'facebook',
                'created_at' => 1663274081,
            ],
            'contact' => $this->buildContact($phone),
            'message' => [
                'status' => [
                    ['value' => 'pending', 'timestamp' => 1662965213],
                ],
                'message' => [
                    'text' => 'Hello, I need help with my order',
                    'type' => 'text',
                    'messageTag' => 'ACCOUNT_UPDATE',
                ],
                'traffic' => 'incoming',
                'channelId' => 123,
                'contactId' => 1,
                'messageId' => 1262965213,
                'timestamp' => 1662965213,
                'channelMessageId' => 123,
            ],
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'message.received',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('message.received', $result['event_type']);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('message_id', $result['result']);
        $this->assertArrayHasKey('channel_id', $result['result']);
        $this->assertFalse($result['result']['is_from_me']);
    }

    public function testProcessOutgoingMessage(): void
    {
        $phone = '+60123456780';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Initial message');
        $this->dispatchWebhookJob($incomingPayload);

        $payload = [
            'user' => [
                'id' => 2,
                'email' => 'agent@sample.com',
                'lastName' => 'Smith',
                'firstName' => 'Agent',
            ],
            'sender' => [
                'type' => 'message',
                'source' => 'user',
                'teamId' => 3489,
                'userId' => 25262,
            ],
            'channel' => [
                'id' => 1,
                'meta' => '{}',
                'name' => 'WhatsApp',
                'source' => 'facebook',
                'created_at' => 1663274081,
            ],
            'contact' => $this->buildContact($phone),
            'message' => [
                'status' => [
                    ['value' => 'pending', 'timestamp' => 1662965213],
                ],
                'message' => [
                    'text' => 'Hi, let me help you with that',
                    'type' => 'text',
                ],
                'traffic' => 'outgoing',
                'channelId' => 123,
                'contactId' => 1,
                'messageId' => 1262965214,
                'timestamp' => 1662965214,
                'channelMessageId' => 124,
            ],
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'message.sent',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('message.sent', $result['event_type']);
        $this->assertArrayHasKey('result', $result);
        $this->assertTrue($result['result']['is_from_me']);
    }

    public function testProcessCommentCreatedAddsToNotesChannel(): void
    {
        $phone = '+60123456781';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Customer message');
        $this->dispatchWebhookJob($incomingPayload);

        $payload = [
            'text' => 'Internal note: customer needs follow-up',
            'contact' => $this->buildContact($phone),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'comment.created',
            'attachments' => [
                [
                    'ext' => 'mp4',
                    'url' => 'https://domain.com/files/video.mp4',
                    'size' => 95283,
                    'type' => 'video',
                    'fileName' => 'video.mp4',
                    'mimeType' => 'video/mp4',
                ],
            ],
            'mentionedUserIds' => [1, 2],
            'mentionedUserEmails' => ['test@example.com'],
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('comment.created', $result['event_type']);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('notes_channel_id', $result['result']);
        $this->assertArrayHasKey('lead_id', $result['result']);
    }

    public function testProcessConversationOpened(): void
    {
        $phone = '+60123456782';

        $payload = [
            'contact' => $this->buildContact($phone),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'conversation.opened',
            'conversation' => [
                'source' => 'api',
                'conversationOpenedAt' => 1663274081,
                'firstIncomingMessage' => 'hello',
                'firstIncomingMessageChannelId' => 1,
            ],
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('conversation.opened', $result['event_type']);
        $this->assertEquals('conversation_opened', $result['result']['status']);
    }

    public function testProcessConversationClosed(): void
    {
        $phone = '+60123456783';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Some question');
        $this->dispatchWebhookJob($incomingPayload);

        $payload = [
            'contact' => $this->buildContact($phone),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'conversation.closed',
            'conversation' => [
                'summary' => 'Customer asked about pricing',
                'category' => 'sales',
                'closedBy' => [
                    'id' => 2,
                    'email' => 'agent@sample.com',
                    'lastName' => 'Smith',
                    'firstName' => 'Agent',
                ],
                'closedTime' => 1663274081,
                'openedTime' => 1663274001,
                'closedBySource' => 'user',
                'incomingMessageCount' => 5,
                'outgoingMessageCount' => 3,
            ],
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('conversation.closed', $result['event_type']);
        $this->assertEquals('conversation_closed', $result['result']['status']);
        $this->assertArrayHasKey('lead_id', $result['result']);
    }

    public function testProcessContactUpdated(): void
    {
        $phone = '+60123456784';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Hello');
        $this->dispatchWebhookJob($incomingPayload);

        $payload = [
            'action' => 'updated',
            'contact' => $this->buildContact($phone, 'Jane', 'Updated'),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'contact.updated',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('contact.updated', $result['event_type']);
        $this->assertEquals('contact_updated', $result['result']['status']);
        $this->assertArrayHasKey('people_id', $result['result']);
    }

    public function testProcessContactTagUpdated(): void
    {
        $phone = '+60123456785';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Hello');
        $this->dispatchWebhookJob($incomingPayload);

        $contact = $this->buildContact($phone);
        $contact['tags'] = ['sampleTag1', 'sampleTag2'];

        $payload = [
            'tag' => 'sampleTag2',
            'action' => 'add',
            'contact' => $contact,
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'contact.tag.updated',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('contact.tag.updated', $result['event_type']);
        $this->assertEquals('contact_tag_updated', $result['result']['status']);
    }

    public function testProcessContactAssigneeUpdated(): void
    {
        $phone = '+60123456786';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Hello');
        $this->dispatchWebhookJob($incomingPayload);

        $payload = [
            'contact' => $this->buildContact($phone),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'contact.assignee.updated',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('contact.assignee.updated', $result['event_type']);
        $this->assertEquals('contact_assignee_updated', $result['result']['status']);
    }

    public function testProcessContactLifecycleUpdated(): void
    {
        $phone = '+60123456787';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Hello');
        $this->dispatchWebhookJob($incomingPayload);

        $payload = [
            'action' => 'updated',
            'contact' => $this->buildContact($phone),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'contact.lifecycle.updated',
            'lifecycle' => 'New Lead',
            'oldLifecycle' => 'Hot Lead',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('contact.lifecycle.updated', $result['event_type']);
        $this->assertEquals('contact_lifecycle_updated', $result['result']['status']);
        $this->assertEquals('New Lead', $result['result']['lifecycle']);
        $this->assertEquals('Hot Lead', $result['result']['old_lifecycle']);
    }

    public function testProcessCallEnded(): void
    {
        $phone = '+60123456788';

        $incomingPayload = $this->buildIncomingMessagePayload($phone, 'Hello');
        $this->dispatchWebhookJob($incomingPayload);

        $payload = [
            'call' => [
                'id' => Str::uuid()->toString(),
                'status' => 'completed',
                'channel' => 'Whatsapp Business - Calls only',
                'end_time' => 1769402629,
                'language' => 'eng',
                'direction' => 'inbound',
                'start_time' => 1769402615,
                'transcript' => [
                    ['end' => 3.099, 'text' => 'Hello', 'start' => 2.499, 'speaker' => 'contact'],
                    ['end' => 7.899, 'text' => 'I would like to ask about pricing', 'start' => 3.099, 'speaker' => 'contact'],
                    ['end' => 3.099, 'text' => 'Sure, let me help you', 'start' => 2.499, 'speaker' => 'agent'],
                ],
                'callSummary' => [
                    'title' => 'Pricing Inquiry',
                    'summary' => ['Customer asked about pricing plans.'],
                    'actionItems' => ['Send pricing document to customer.'],
                ],
                'recordingURL' => 'https://s3.amazonaws.com/recording.mp3',
                'durationSeconds' => 120,
            ],
            'user' => [
                'id' => 31448,
                'role' => 'user',
                'email' => 'agent@mail.com',
                'lastName' => 'Smith',
                'firstName' => 'Agent',
            ],
            'channel' => [
                'id' => 1,
                'name' => 'Whatsapp Business - Calls only',
                'source' => 'whatsapp_business',
                'created_at' => 1663274081,
            ],
            'contact' => $this->buildContact($phone),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'call.ended',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('call.ended', $result['event_type']);
        $this->assertEquals('call_processed', $result['result']['status']);
        $this->assertEquals('inbound', $result['result']['direction']);
        $this->assertEquals(120, $result['result']['duration_seconds']);
    }

    public function testUnknownEventTypeIsAcknowledged(): void
    {
        $payload = [
            'contact' => $this->buildContact('+60123456700'),
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'some.unknown.event',
        ];

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('some.unknown.event', $result['event_type']);
        $this->assertEquals('acknowledged', $result['result']['status']);
    }

    private function buildContact(
        string $phone,
        string $firstName = 'John',
        string $lastName = 'Doe'
    ): array {
        return [
            'id' => 1,
            'email' => 'johndoe@sample.com',
            'phone' => $phone,
            'status' => 'open',
            'assignee' => [
                'id' => 2,
                'email' => 'agent@sample.com',
                'lastName' => 'Smith',
                'firstName' => 'Agent',
            ],
            'language' => 'en',
            'lastName' => $lastName,
            'firstName' => $firstName,
            'created_at' => 1663274081,
            'profilePic' => 'https://cdn.chatapi.net/johndoe.png',
            'countryCode' => 'MY',
        ];
    }

    private function buildIncomingMessagePayload(string $phone, string $text): array
    {
        return [
            'sender' => ['type' => 'message', 'source' => 'contact'],
            'channel' => ['id' => 1, 'name' => 'WhatsApp', 'source' => 'facebook', 'created_at' => 1663274081],
            'contact' => $this->buildContact($phone),
            'message' => [
                'message' => ['text' => $text, 'type' => 'text'],
                'traffic' => 'incoming',
                'channelId' => 123,
                'contactId' => 1,
                'messageId' => random_int(1000000, 9999999),
                'timestamp' => time(),
            ],
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'message.received',
        ];
    }

    private function dispatchWebhookJob(array $payload): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            $payload
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        Queue::fake();

        $job = new ProcessRespondIOWebhookJob($webhookRequest);

        return $job->handle();
    }
}
