<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * Covers what a receiver ANSWERS with — the handshake short-circuit and the configurable async ack.
 * Drives them through ProcessSlackWebhookJob because it is the only job that implements the
 * handshake hook; the point of the last test is that everything else is untouched.
 */
final class ReceiverWebhookResponseTest extends TestCase
{
    private const string SIGNING_SECRET = 'shhh';

    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $kanvasApp = app(Apps::class);
        $user = auth()->user();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackWebhookJob::class],
            ['name' => 'ProcessSlackWebhookJob']
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($kanvasApp->getId())
            ->user($user->getId())
            ->company($user->getCurrentCompany()->getId())
            ->create([
                'action_id' => $action->getId(),
                'run_async' => 1,
                'configuration' => [
                    ConfigurationEnum::SIGNING_SECRET->value => self::SIGNING_SECRET,
                ],
            ]);
    }

    public function testHandshakeIsAnsweredInBandAndNeverReachesTheQueue(): void
    {
        Queue::fake();

        $this->postSigned(['type' => 'url_verification', 'challenge' => 'c0ffee'])
            ->assertOk()
            ->assertExactJson(['challenge' => 'c0ffee']);

        Queue::assertNothingPushed();
    }

    public function testUnsignedRequestIsRejected(): void
    {
        $this->postJson('/v1/receiver/' . $this->receiver->uuid, ['type' => 'url_verification'])
            ->assertStatus(401);
    }

    public function testAsyncResponseBodyIsConfigurablePerReceiver(): void
    {
        Queue::fake();

        $this->receiver->configuration = [
            ...$this->receiver->configuration,
            'async_response' => ['ok' => true],
            'async_response_status' => 202,
        ];
        $this->receiver->saveOrFail();

        $this->postSigned($this->eventPayload())
            ->assertStatus(202)
            ->assertExactJson(['ok' => true]);

        Queue::assertPushed(ProcessSlackWebhookJob::class);
    }

    public function testReceiverWithoutAnAsyncResponseKeepsTheDefaultAck(): void
    {
        Queue::fake();

        $this->postSigned($this->eventPayload())
            ->assertOk()
            ->assertExactJson(['message' => 'Receiver processed']);

        Queue::assertPushed(ProcessSlackWebhookJob::class);
    }

    public function testAnInactiveReceiverDropsTheEvent(): void
    {
        Queue::fake();

        $this->receiver->is_active = false;
        $this->receiver->saveOrFail();

        // 200, not 4xx — a provider that keeps retrying a failing endpoint eventually disables
        // delivery on its side, which the customer would have to undo by hand on reconnect.
        $this->postSigned($this->eventPayload())
            ->assertOk()
            ->assertExactJson(['message' => 'Receiver is not active']);

        Queue::assertNotPushed(ProcessSlackWebhookJob::class);
    }

    public function testAnInactiveReceiverStillAnswersTheHandshake(): void
    {
        Queue::fake();

        $this->receiver->is_active = false;
        $this->receiver->saveOrFail();

        // Otherwise a customer re-verifying their endpoint can't set the integration back up.
        $this->postSigned(['type' => 'url_verification', 'challenge' => 'c0ffee'])
            ->assertOk()
            ->assertExactJson(['challenge' => 'c0ffee']);
    }

    private function eventPayload(): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => 'T0001',
            'event_id' => 'Ev0001',
            'event' => ['type' => 'message', 'user' => 'U0001', 'channel' => 'C0001', 'text' => 'hi'],
        ];
    }

    /**
     * Slack signs the RAW body, so the request has to carry the exact bytes we hashed — hence
     * call() with an explicit body rather than postJson()'s re-encoding.
     */
    private function postSigned(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $timestamp = (string) time();

        return $this->call(
            'POST',
            '/v1/receiver/' . $this->receiver->uuid,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
                'HTTP_X_SLACK_SIGNATURE' => 'v0=' . hash_hmac(
                    'sha256',
                    'v0:' . $timestamp . ':' . $body,
                    self::SIGNING_SECRET
                ),
            ],
            $body,
        );
    }
}
