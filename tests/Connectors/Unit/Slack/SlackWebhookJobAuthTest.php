<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Slack;

use Illuminate\Http\Request;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackListenerWebhookJob;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SlackWebhookJobAuthTest extends TestCase
{
    public static function jobClassProvider(): array
    {
        return [
            'agent' => [ProcessSlackWebhookJob::class],
            'listener' => [ProcessSlackListenerWebhookJob::class],
        ];
    }

    #[DataProvider('jobClassProvider')]
    public function testTheUrlChallengeIsAnsweredBeforeAnySecretExists(string $jobClass): void
    {
        // Slack verifies the Request URL while creating the app from our manifest. Reject it and the
        // customer cannot create the app at all, so the connector can never be set up.
        $this->assertTrue(
            $jobClass::authenticateRequest(
                $this->request(['type' => 'url_verification', 'challenge' => 'abc']),
                $this->receiver(signingSecret: null),
            )
        );
    }

    #[DataProvider('jobClassProvider')]
    public function testARealEventIsStillRejectedBeforeAnySecretExists(string $jobClass): void
    {
        $this->assertFalse(
            $jobClass::authenticateRequest(
                $this->request(['type' => 'event_callback', 'event' => ['type' => 'message']]),
                $this->receiver(signingSecret: null),
            )
        );
    }

    #[DataProvider('jobClassProvider')]
    public function testOnceConnectedEvenTheChallengeMustBeSigned(string $jobClass): void
    {
        $this->assertFalse(
            $jobClass::authenticateRequest(
                $this->request(['type' => 'url_verification', 'challenge' => 'abc']),
                $this->receiver(signingSecret: 'shhh'),
            )
        );
    }

    private function request(array $payload): Request
    {
        return Request::create('https://localhost/v1/receiver/x', 'POST', $payload);
    }

    private function receiver(?string $signingSecret): ReceiverWebhook
    {
        $receiver = new ReceiverWebhook();
        $receiver->configuration = $signingSecret === null
            ? []
            : [ConfigurationEnum::SIGNING_SECRET->value => $signingSecret];

        return $receiver;
    }
}
