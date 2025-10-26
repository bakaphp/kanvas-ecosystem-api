<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Actions;

use Kanvas\Souk\Loyalty\Models\LoyaltyOffer;
use Kanvas\Souk\Loyalty\Models\LoyaltyOfferAssignment;
use Kanvas\Users\Models\Users;

class AssignOfferAction
{
    public function __construct(
        private Users $user,
        private LoyaltyOffer $offer
    ) {
    }

    public function execute(): LoyaltyOfferAssignment
    {
        // Check if user already has this offer assigned
        $existingAssignment = LoyaltyOfferAssignment::fromApp($this->offer->app)
            ->where('users_id', $this->user->getId())
            ->where('loyalty_offers_id', $this->offer->getId())
            ->where('status', '!=', 'expired')
            ->first();

        if ($existingAssignment) {
            return $existingAssignment;
        }

        // Create new assignment
        return LoyaltyOfferAssignment::create([
            'apps_id' => $this->offer->apps_id,
            'users_id' => $this->user->getId(),
            'loyalty_offers_id' => $this->offer->getId(),
            'status' => 'assigned',
            'expires_at' => $this->offer->getExpirationDateTime(),
        ]);
    }
}