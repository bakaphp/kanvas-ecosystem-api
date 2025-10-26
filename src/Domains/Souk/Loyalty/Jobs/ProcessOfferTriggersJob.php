<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Loyalty\Actions\CheckOfferTriggersAction;
use Kanvas\Users\Models\Users;

/**
 * Job to process offer triggers after order completion.
 * This demonstrates how to integrate the configurable offer system
 * without hardcoding any business logic.
 */
class ProcessOfferTriggersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $orderId,
        private int $userId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $order = Order::find($this->orderId);
        $user = Users::find($this->userId);

        if (!$order || !$user) {
            return;
        }

        // Only process completed orders
        if ($order->status !== 'completed') {
            return;
        }

        // Check for applicable offers
        $triggerAction = new CheckOfferTriggersAction($user, $order);
        $triggeredOffers = $triggerAction->execute();

        // Notify user about new offers
        foreach ($triggeredOffers as $offerAssignment) {
            $offer = $offerAssignment->loyaltyOffer;
            
            // TODO: Send notification (email, push, in-app modal, etc.)
            // $user->notify(new OfferAvailableNotification($offerAssignment));
            
            // Log for analytics
            Log::info('Offer triggered for user', [
                'user_id' => $user->getId(),
                'order_id' => $order->getId(),
                'offer_id' => $offer->getId(),
                'offer_name' => $offer->name,
                'trigger_type' => $offer->trigger_type,
                'expires_at' => $offerAssignment->expires_at,
            ]);
        }

        if (count($triggeredOffers) > 0) {
            Log::info('Offers processed for order completion', [
                'user_id' => $user->getId(),
                'order_id' => $order->getId(),
                'offers_triggered' => count($triggeredOffers),
            ]);
        }
    }
}