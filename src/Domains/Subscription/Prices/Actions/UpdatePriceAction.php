<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Actions;

use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price;
use Stripe\Price as StripePrice;

class UpdatePriceAction
{
    public function __construct(
        protected Price $price,
        protected PriceDto $dto
    ) {
    }

    /**
     * Execute the action to update an existing price.
     */
    public function execute(bool $updateInStripe = true): Price
    {
        if ($updateInStripe) {
            StripePrice::update(
                $this->dto->stripe_id,
                [
                    'active' => $this->dto->is_active,
                ]
            );
        }
        $this->price->update([
            'is_active' => $this->dto->is_active,
            'amount' => $this->dto->amount,
            'currency' => $this->dto->currency,
            'interval' => $this->dto->interval,
        ]);

        return $this->price;
    }

    public static function import(Price $price, PriceDto $dto): Price
    {
        $price->update([
            'is_active' => $dto->is_active,
            'amount' => $dto->amount,
            'currency' => $dto->currency,
            'interval' => $dto->interval,
        ]);

        return $price;
    }
}
