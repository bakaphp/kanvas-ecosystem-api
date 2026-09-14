<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Illuminate\Http\Request;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Override;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;

#[WorkflowAction(
    name: 'Stripe Cashier Webhook',
    description: 'Receiver that hands Stripe billing events to Laravel Cashier, which keeps app SUBSCRIPTIONS '
        . 'in step. This is the platform-billing path — use the order-payment receiver for customer '
        . 'checkout instead.',
    integration: IntegrationsEnum::STRIPE,
)]
class CashierStripeWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $stripeSecret = $this->receiver->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);
        if (is_string($stripeSecret) && $stripeSecret !== '') {
            Stripe::setApiKey($stripeSecret);
        }

        Cashier::useCustomerModel(AppsStripeCustomer::class);

        $rawPayload = is_string($this->webhookRequest->payload)
            ? $this->webhookRequest->payload
            : (string) json_encode($this->webhookRequest->payload);

        $request = Request::create(
            '/cashier/webhook',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawPayload,
        );

        $response = app(WebhookController::class)->handleWebhook($request);

        return [
            'message' => 'Stripe Webhook Sync',
            'status' => $response->getStatusCode(),
        ];
    }

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $secret = $receiver->app->get(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value);
        $signature = $request->header('Stripe-Signature');

        if (empty($secret) || ! is_string($signature) || $signature === '') {
            return false;
        }

        try {
            Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (SignatureVerificationException | UnexpectedValueException) {
            return false;
        }

        return true;
    }
}
