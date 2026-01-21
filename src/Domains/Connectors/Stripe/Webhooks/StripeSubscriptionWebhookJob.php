<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use Kanvas\Users\Models\Users;

class StripePaymentIntentWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        //$regionId = $this->receiver->configuration['region_id'];
        $payload = $this->webhookRequest->payload;
        $receiverPayload = $this->receiver;
        $eventType = $payload['data']['type'] ?? null;
        $clientSecret = $payload['data']['object']['client_secret'] ?? null;
        

        return match ($eventType) {
            'customer.subscription.created' => $this->handleSubscriptionCreated($receiverPayload, $payload),
            'customer.invoice.paid' => $this->handleInvoicePaid($receiverPayload, $payload),
            'customer.subscription.updated' => $this->handleSubscriptionCancellation($receiverPayload, $payload),
            default => [
                'message' => 'Event type not handled: ' . $eventType,
                'response' => null,
            ],
        };

        return [
            'message' => 'Payment intent processed successfully',
        ];
    }

    /**
     * @todo move to use commerce to register purchase
     */
    protected function handleSubscriptionCreated(ReceiverWebhook $receiverPayload, array $payload): array
    {
        $user = $receiverPayload->user;
        $this->addCredits($user, 'video', 'flex-credits', 200);
        $this->addCredits($user, 'video', 'nano-banana', 100);
        $this->addCredits($user, 'video', 'veo', 20);

        return [
            'message' => 'Successfully processed subscription created event and added credits to user ' . $user->getId(),
            'response' => null,
        ];
    }

    /**
     * @todo move to use commerce to register purchase
     */
    protected function handleInvoicePaid(ReceiverWebhook $receiverPayload, array $payload): array
    {
        $user = $receiverPayload->user;
        $this->addCredits($user, 'video', 'flex-credits', 200);

        //Get whatever credits the user has left for nano banana and either top up to 100 or add 100 if none exist
        $credits = json_decode($user->get('order_credits'));
        $nanoBananaCredits = $credits->video->{"nano-banana"} ?? $this->addCredits($user, 'video', 'nano-banana', 100);
        if ($nanoBananaCredits < 100) {
            $this->addCredits($user, 'video', 'nano-banana', 100 - $nanoBananaCredits);
        }
        return [
            'message' => 'Successfully processed invoice paid event and added credits to user ' . $user->getId(),
            'response' => null,
        ];
    }

    /**
     * @todo move to use commerce to register purchase
     */
    protected function handleSubscriptionCancellation(ReceiverWebhook $receiverPayload, array $payload): array
    {
        $subscription = $payload['data']['object'];
        return [
            'message' => 'Subscription is set to cancel at period end, no action taken.',
            'response' => "subscription id: " . $subscription['id'],
            'cancel_at_period_end' => $subscription['cancel_at_period_end'],
        ];
    }

    protected function addCredits(Users $user,string $type, string $nameOfModel, int $amount): void
    {
        $credits = json_decode($user->get('order_credits'));
        if (isset($credits->$type)) {
            $credits->$type->$nameOfModel += $amount;
        } else {
            $credits->$type = new \stdClass();
            $credits->$type->$nameOfModel = $amount;
        }
        $user->set('order_credits', json_encode($credits));
    }
}