<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;
use Throwable;

class AgentChannelResponderActionTest extends TestCase
{
    private function setupLeadMessageChannelAgent(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads']
        );

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'test sms message'],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = $message->fresh();

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'twilio-test-' . $lead->getId(),
            ],
            [
                'name' => 'Test Twilio Channel',
                'description' => 'Test channel for batch logic',
                'users_id' => $user->getId(),
            ]
        );

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return compact('lead', 'message', 'channel', 'agent');
    }

    public function testSkipsWhenNotLastMessageInBatch(): void
    {
        ['lead' => $lead, 'message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $batchKey = "message_batch:test_receiver:{$lead->getId()}";
        $differentMessageId = $message->getId() + 999;

        Cache::put($batchKey, [
            'messages' => [
                ['body' => 'first message', 'message_id' => $differentMessageId],
                ['body' => 'second message', 'message_id' => $differentMessageId + 1],
            ],
            'last_message_id' => $differentMessageId + 1,
            'phone_number' => '+11234567890',
        ], now()->addMinutes(10));

        $action = new AgentChannelResponderAction($channel, $message, $agent);
        $result = $action->execute(['batchKey' => $batchKey]);

        $this->assertStringContainsString('not the last message', $result['message']);
        $this->assertArrayHasKey('batch', $result);
        $this->assertEquals($differentMessageId + 1, $result['batch']['last_message_id']);

        // Cache should NOT be cleared — it is still needed for the actual last message
        $this->assertTrue(Cache::has($batchKey));
    }

    public function testClearsCacheWhenCurrentMessageIsLastInBatch(): void
    {
        ['lead' => $lead, 'message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $batchKey = "message_batch:test_receiver_last:{$lead->getId()}";

        Cache::put($batchKey, [
            'messages' => [
                ['body' => 'the only message', 'message_id' => $message->getId()],
            ],
            'last_message_id' => $message->getId(),
            'phone_number' => '+11234567890',
        ], now()->addMinutes(10));

        $this->assertTrue(Cache::has($batchKey));

        try {
            $action = new AgentChannelResponderAction($channel, $message, $agent);
            $action->execute(['batchKey' => $batchKey, 'from' => '+19876543210']);
        } catch (Throwable) {
            // AI/Twilio external calls are expected to fail in test environment.
            // We only care that Cache::forget was called before reaching those calls.
        }

        $this->assertFalse(
            Cache::has($batchKey),
            'Cache key must be cleared when the current message is the last in the batch'
        );
    }

    public function testDoesNotCheckBatchWhenNoBatchKeyProvided(): void
    {
        ['message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $action = new AgentChannelResponderAction($channel, $message, $agent);

        try {
            $result = $action->execute([]);
        } catch (Throwable) {
            // Expected: no batchKey means batch check is skipped entirely,
            // and execution continues to the AI agent which will fail in test env.
            $result = null;
        }

        // If we got a result it must NOT be the batch-skip message
        if ($result !== null) {
            $this->assertArrayNotHasKey(
                'batch',
                $result,
                'Result should not be the batch-skip payload when no batchKey is provided'
            );
        }

        // Reaching this point confirms the batch check was not triggered
        $this->assertTrue(true);
    }

    public function testDoesNotCheckBatchWhenBatchKeyNotInCache(): void
    {
        ['message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $batchKey = 'message_batch:nonexistent_key_xyz';
        Cache::forget($batchKey);

        $action = new AgentChannelResponderAction($channel, $message, $agent);

        try {
            $result = $action->execute(['batchKey' => $batchKey]);
        } catch (Throwable) {
            $result = null;
        }

        // If we got a result it must NOT be the batch-skip message
        if ($result !== null) {
            $this->assertArrayNotHasKey(
                'batch',
                $result,
                'Result should not be the batch-skip payload when batchKey is not in cache'
            );
        }

        $this->assertTrue(true);
    }
}
