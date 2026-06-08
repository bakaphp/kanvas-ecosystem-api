<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Webhooks\CashierStripeWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class CashierWebhookSignatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Stripe integration tests are skipped in CI');
        }
    }

    public function testAuthenticateRequestAcceptsValidStripeSignature(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $secret = 'whsec_test_' . bin2hex(random_bytes(16));
        $app->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, $secret);

        $payload = (string) json_encode(['id' => 'evt_test_123', 'type' => 'customer.subscription.updated']);
        $request = $this->buildSignedRequest($payload, $secret);

        $receiver = $this->makeReceiver($app, $user, $company);

        $this->assertTrue(CashierStripeWebhookJob::authenticateRequest($request, $receiver));
    }

    public function testAuthenticateRequestRejectsTamperedPayload(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $secret = 'whsec_test_' . bin2hex(random_bytes(16));
        $app->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, $secret);

        $signedPayload = (string) json_encode(['id' => 'evt_test_123', 'type' => 'customer.subscription.updated']);
        $request = $this->buildSignedRequest($signedPayload, $secret);

        $tamperedPayload = (string) json_encode(['id' => 'evt_test_456', 'type' => 'customer.subscription.deleted']);
        $tampered = Request::create('/', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $request->header('Stripe-Signature'),
        ], $tamperedPayload);

        $receiver = $this->makeReceiver($app, $user, $company);

        $this->assertFalse(CashierStripeWebhookJob::authenticateRequest($tampered, $receiver));
    }

    public function testAuthenticateRequestRejectsMissingSignatureHeader(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, 'whsec_test_secret');

        $payload = (string) json_encode(['id' => 'evt_test_123', 'type' => 'customer.subscription.updated']);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        $receiver = $this->makeReceiver($app, $user, $company);

        $this->assertFalse(CashierStripeWebhookJob::authenticateRequest($request, $receiver));
    }

    public function testAuthenticateRequestRejectsWhenAppHasNoWebhookSecret(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, '');

        $payload = (string) json_encode(['id' => 'evt_test_123', 'type' => 'customer.subscription.updated']);
        $request = $this->buildSignedRequest($payload, 'whsec_anything');

        $receiver = $this->makeReceiver($app, $user, $company);

        $this->assertFalse(CashierStripeWebhookJob::authenticateRequest($request, $receiver));
    }

    private function buildSignedRequest(string $payload, string $secret): Request
    {
        $timestamp = time();
        $signedContent = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedContent, $secret);
        $header = 't=' . $timestamp . ',v1=' . $signature;

        return Request::create('/', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $payload);
    }

    private function makeReceiver(Apps $app, $user, $company): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate([
            'name' => 'Cashier Stripe Webhook',
            'model_name' => CashierStripeWebhookJob::class,
        ]);

        return ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create(['action_id' => $action->getId()]);
    }
}
