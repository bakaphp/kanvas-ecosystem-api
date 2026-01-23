<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Webhooks;

use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

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

    protected function handleSubscriptionCreated(ReceiverWebhook $receiverPayload, array $payload): array
    {
        $user = $receiverPayload->user;
        $app = $receiverPayload->app;
        $userCredits = $user->get('order_credits') ?? [];
        $modelCreditsStructure = $app->get('model_credits_structure', []);

        foreach ($modelCreditsStructure as $type => $models) {
            foreach ($models as $model) {
                $this->addCredits($user, $type, $model, $model['amount']);
            }
        }

        return [
            'message' => 'Successfully processed subscription created event and added credits to user ' . $user->getId(),
            'response' => null,
        ];
    }

    protected function handleInvoicePaid(ReceiverWebhook $receiverPayload, array $payload): array
    {
        $user = $receiverPayload->user;
        $app = $receiverPayload->app;
        $userCredits = $user->get('order_credits') ?? [];
        $modelCreditsStructure = $app->get('model_credits_structure', []);

        foreach ($modelCreditsStructure as $type => $models) {
            foreach ($models as $model) {
                if (! $userCredits[$type][$model]['top_off'] || ! $model['one_time_credit']) {
                    $this->addCredits($user, $type, $model, $model['amount']);
                } else {
                    $this->topUpCredits($user, $type, $model, $model['amount']);
                }
            }
        }

        return [
            'message' => 'Successfully processed invoice paid event and added credits to user ' . $user->getId(),
            'response' => null,
        ];
    }

    /**
     * @todo Something else could be done here but for now just log and return
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

    protected function addCredits(Users $user, string $type, string $model, int $amount): void
    {
        $credits = $user->get('order_credits');
        if (isset($credits[$type])) {
            $credits[$type][$model] += $amount;
        } else {
            $credits[$type] = [];
            $credits[$type][$model] = $amount;
        }
        $user->set('order_credits', json_encode($credits));
    }

    protected function topUpCredits(Users $user, string $type, string $model, int $minimumAmount): void
    {
        $credits = $user->get('order_credits');
        $currentAmount = $credits[$type][$model] ?? 0;

        if ($currentAmount < $minimumAmount) {
            $this->addCredits($user, $type, $model, $minimumAmount - $currentAmount);
        }
    }
}
