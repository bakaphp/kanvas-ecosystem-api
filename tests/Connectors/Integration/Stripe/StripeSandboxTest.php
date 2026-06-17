<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Stripe\Exception\CardException;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Contract test against the real Stripe sandbox API — catches drift between
 * FakeStripeClient's assumed response shapes and what the live API returns.
 *
 * Skipped in CI, and unless TEST_STRIPE_SECRET_KEY / STRIPE_SECRET_KEY (sk_test_) is set.
 */
class StripeSandboxTest extends TestCase
{
    protected StripeClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('CI')) {
            $this->markTestSkipped('Stripe sandbox integration tests are skipped in CI');
        }

        $secret = env('TEST_STRIPE_SECRET_KEY') ?? env('STRIPE_SECRET_KEY');

        if (empty($secret)) {
            $this->markTestSkipped('Stripe sandbox key not configured (TEST_STRIPE_SECRET_KEY or STRIPE_SECRET_KEY)');
        }

        if (! str_starts_with((string) $secret, 'sk_test_')) {
            $this->markTestSkipped('Refusing to run sandbox contract tests against a non-test key');
        }

        $this->stripe = new StripeClient($secret);
    }

    /** Mirrors the param shape StripeProcessor::createIntent() builds (minus customer/pm). */
    private function intentParams(array $overrides = []): array
    {
        return array_merge([
            'amount' => 1000,
            'currency' => 'usd',
            'confirm' => true,
            'off_session' => false,
            'capture_method' => 'automatic',
            'payment_method' => 'pm_card_visa',
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            'metadata' => [
                'kanvas_app_id' => '0',
                'kanvas_company_id' => '0',
                'kanvas_payment_id' => '0',
                'kanvas_order_id' => '0',
                'kanvas_test' => 'sandbox-contract',
            ],
        ], $overrides);
    }

    public function testTokenizationContractCustomerAndPaymentMethodShapes(): void
    {
        $customer = $this->stripe->customers->create([
            'email' => 'sandbox-contract@example.com',
            'metadata' => ['kanvas_app_id' => '0', 'kanvas_company_id' => '0', 'kanvas_user_id' => '0'],
        ]);

        $this->assertStringStartsWith('cus_', $customer->id);
        $this->assertFalse((bool) ($customer->deleted ?? false), 'tokenize() checks the deleted flag on retrieve');

        $attached = $this->stripe->paymentMethods->attach('pm_card_visa', ['customer' => $customer->id]);
        $retrieved = $this->stripe->paymentMethods->retrieve($attached->id);

        $this->assertStringStartsWith('pm_', $retrieved->id);
        $this->assertSame('card', $retrieved->type);
        $this->assertNotEmpty($retrieved->card->last4, 'TokenizeResult.lastFour reads card.last4');
        $this->assertNotEmpty($retrieved->card->brand, 'TokenizeResult.brand reads card.brand');

        $detached = $this->stripe->paymentMethods->detach($retrieved->id);
        $this->assertNull($detached->customer, 'deleteToken() detach clears the customer');

        $this->stripe->customers->delete($customer->id);
    }

    public function testFrictionlessIntentSucceedsWithChargeAndClientSecret(): void
    {
        $intent = $this->stripe->paymentIntents->create(
            $this->intentParams(),
            ['idempotency_key' => 'pi:payment:test-' . uniqid()],
        );

        $this->assertSame('succeeded', $intent->status, 'mapIntentStatus succeeded → PAID');
        $this->assertStringStartsWith('pi_', $intent->id);
        $this->assertNotEmpty($intent->latest_charge, 'syncPaymentFromIntent reads stripe_charge_id from latest_charge');
        $this->assertStringContainsString('_secret_', (string) $intent->client_secret, 'startChallenge reads client_secret');
        $this->assertIsArray($intent->toArray());
        $this->assertSame('sandbox-contract', $intent->metadata['kanvas_test']);
    }

    public function testThreeDSIntentRequiresActionWithClientSecret(): void
    {
        $intent = $this->stripe->paymentIntents->create(
            $this->intentParams(['payment_method' => 'pm_card_threeDSecureRequired']),
        );

        $this->assertSame('requires_action', $intent->status, 'mapIntentStatus requires_action → PENDING_AUTHORIZATION');
        $this->assertNotEmpty($intent->client_secret, 'startChallenge() data.client_secret');
        $this->assertNotEmpty($intent->id, 'startChallenge() data.payment_intent_id');
        $this->assertNotNull($intent->next_action, 'what Stripe.js handleNextAction consumes');

        $refetched = $this->stripe->paymentIntents->retrieve($intent->id);
        $this->assertSame('requires_action', $refetched->status, 'finalizeChallenge retrieve: still pending until the browser acts');

        $this->stripe->paymentIntents->cancel($intent->id);
    }

    public function testManualCaptureAuthorizeThenCaptureThenRefund(): void
    {
        $intent = $this->stripe->paymentIntents->create(
            $this->intentParams(['capture_method' => 'manual']),
        );

        $this->assertSame('requires_capture', $intent->status, 'mapIntentStatus requires_capture → AUTHORIZED');

        $captured = $this->stripe->paymentIntents->capture($intent->id, ['amount_to_capture' => 1000]);
        $this->assertSame('succeeded', $captured->status);
        $this->assertNotEmpty($captured->latest_charge);

        $refund = $this->stripe->refunds->create(
            [
                'payment_intent' => $captured->id,
                'amount' => 500,
                'metadata' => ['kanvas_payment_id' => '0'],
            ],
            ['idempotency_key' => 'refund:test-' . uniqid()],
        );

        $this->assertStringStartsWith('re_', $refund->id, 'PaymentRefund.processor_refund_id stores this');
        $this->assertContains($refund->status, ['succeeded', 'pending'], 'recordRefund() branches on refund status');
        $this->assertSame(500, $refund->amount, 'refund amount is in cents');
    }

    public function testVoidCancelsUncapturedIntent(): void
    {
        $intent = $this->stripe->paymentIntents->create(
            $this->intentParams(['capture_method' => 'manual']),
        );
        $this->assertSame('requires_capture', $intent->status);

        $canceled = $this->stripe->paymentIntents->cancel($intent->id);
        $this->assertSame('canceled', $canceled->status, 'mapIntentStatus canceled → FAILED (Payment goes CANCELLED via void())');
    }

    public function testDeclinedCardThrowsCardExceptionWithMessage(): void
    {
        try {
            $this->stripe->paymentIntents->create(
                $this->intentParams(['payment_method' => 'pm_card_chargeDeclined']),
            );
            $this->fail('Expected CardException for pm_card_chargeDeclined');
        } catch (CardException $e) {
            $this->assertNotEmpty($e->getMessage(), 'markFailedFromException stores getMessage() in metadata.data.stripe_error');
            $this->assertIsArray($e->getJsonBody());
            $this->assertArrayHasKey('error', $e->getJsonBody());

            $intentId = $e->getJsonBody()['error']['payment_intent']['id'] ?? null;
            if ($intentId) {
                $intent = $this->stripe->paymentIntents->retrieve($intentId);
                $this->assertSame('requires_payment_method', $intent->status, 'declined confirm lands as requires_payment_method → FAILED');
            }
        }
    }
}
