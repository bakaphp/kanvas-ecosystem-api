<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Webhooks\CashierStripeWebhookJob;
use Kanvas\Subscription\Subscriptions\Events\CompanySubscriptionUpdatedEvent;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

final class CashierWebhookEndToEndTest extends TestCase
{
    public function testValidSignatureUpdatesSubscriptionAndBroadcastsCompanyEvent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $secret = 'whsec_test_' . bin2hex(random_bytes(16));
        $app->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, $secret);
        $app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, 'sk_test_dummy');

        $stripeCustomerId = 'cus_e2e_' . Str::random(10);
        $stripeSubscriptionId = 'sub_e2e_' . Str::random(10);
        $customer = AppsStripeCustomer::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'stripe_id' => $stripeCustomerId,
        ]);
        Subscription::create([
            'apps_stripe_customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => $stripeSubscriptionId,
            'stripe_status' => 'active',
            'stripe_price' => 'price_e2e_initial',
            'quantity' => 1,
        ]);

        $receiver = $this->seedReceiver($app, $user, $company);

        $payload = $this->subscriptionUpdatedPayload(
            customerId: $stripeCustomerId,
            subscriptionId: $stripeSubscriptionId,
            status: 'past_due',
            priceId: 'price_e2e_new',
        );
        [$rawBody, $signatureHeader] = $this->signPayload($payload, $secret);

        Event::fake([CompanySubscriptionUpdatedEvent::class]);

        $response = $this->call(
            'POST',
            '/v1/receiver/' . $receiver->uuid,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signatureHeader],
            $rawBody,
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $updated = Subscription::query()->where('stripe_id', $stripeSubscriptionId)->firstOrFail();
        $this->assertSame('past_due', $updated->stripe_status);
        $this->assertSame('price_e2e_new', $updated->stripe_price);

        Event::assertDispatched(
            CompanySubscriptionUpdatedEvent::class,
            fn (CompanySubscriptionUpdatedEvent $e) => $e->broadcastWith()['subscription_id'] === $updated->id
        );
    }

    public function testTamperedSignatureReturns401AndDoesNotMutate(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $secret = 'whsec_test_' . bin2hex(random_bytes(16));
        $app->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, $secret);

        $stripeCustomerId = 'cus_tamper_' . Str::random(10);
        $stripeSubscriptionId = 'sub_tamper_' . Str::random(10);
        $customer = AppsStripeCustomer::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'stripe_id' => $stripeCustomerId,
        ]);
        Subscription::create([
            'apps_stripe_customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => $stripeSubscriptionId,
            'stripe_status' => 'active',
            'stripe_price' => 'price_tamper_initial',
            'quantity' => 1,
        ]);

        $receiver = $this->seedReceiver($app, $user, $company);

        $signedPayload = $this->subscriptionUpdatedPayload(
            customerId: $stripeCustomerId,
            subscriptionId: $stripeSubscriptionId,
            status: 'past_due',
            priceId: 'price_tamper_new',
        );
        [, $signatureHeader] = $this->signPayload($signedPayload, $secret);

        $tamperedPayload = $this->subscriptionUpdatedPayload(
            customerId: $stripeCustomerId,
            subscriptionId: $stripeSubscriptionId,
            status: 'canceled',
            priceId: 'price_tamper_attacker',
        );

        $response = $this->call(
            'POST',
            '/v1/receiver/' . $receiver->uuid,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signatureHeader],
            (string) json_encode($tamperedPayload),
        );

        $this->assertSame(401, $response->getStatusCode());

        $unchanged = Subscription::query()->where('stripe_id', $stripeSubscriptionId)->firstOrFail();
        $this->assertSame('active', $unchanged->stripe_status);
        $this->assertSame('price_tamper_initial', $unchanged->stripe_price);
    }

    private function seedReceiver(Apps $app, $user, $company): ReceiverWebhook
    {
        $action = WorkflowAction::firstOrCreate([
            'model_name' => CashierStripeWebhookJob::class,
        ], ['name' => 'Cashier Stripe Webhook']);

        $receiver = ReceiverWebhook::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'action_id' => $action->getId(),
        ], [
            'users_id' => $user->getId(),
            'name' => 'Stripe Webhook E2E',
            'configuration' => [],
        ]);

        // Receiver must run synchronously so we can assert the response inline
        $receiver->run_async = false;
        $receiver->save();

        return $receiver;
    }

    private function subscriptionUpdatedPayload(
        string $customerId,
        string $subscriptionId,
        string $status,
        string $priceId,
    ): array {
        return [
            'id' => 'evt_' . Str::random(12),
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => $subscriptionId,
                    'customer' => $customerId,
                    'status' => $status,
                    'metadata' => ['type' => 'default'],
                    'items' => [
                        'data' => [[
                            'id' => 'si_' . Str::random(10),
                            'quantity' => 1,
                            'price' => [
                                'id' => $priceId,
                                'product' => 'prod_' . Str::random(10),
                            ],
                        ]],
                    ],
                    'cancel_at_period_end' => false,
                ],
            ],
        ];
    }

    private function signPayload(array $payload, string $secret): array
    {
        $raw = (string) json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);

        return [$raw, 't=' . $timestamp . ',v1=' . $signature];
    }
}
