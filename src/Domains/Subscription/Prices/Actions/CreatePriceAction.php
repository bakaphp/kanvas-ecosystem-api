<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Actions;

use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price;
use Stripe\Price as StripePrice;

class CreatePriceAction
{
    public function __construct(
        protected PriceDto $dto,
    ) {
    }

    /**
     * Execute the action to create or retrieve an existing price.
     */
    public function execute(bool $createInStripe = true): Price
    {
        if ($createInStripe) {
            $newPrice = StripePrice::create([
                'unit_amount' => $this->dto->amount * 100,
                'currency' => $this->dto->currency,
                'recurring' => ['interval' => $this->dto->interval],
                'product' => $this->dto->stripe_id,
            ]);
        }

        return Price::firstOrCreate([
            'stripe_id' => $newPrice?->id ?? $this->dto->stripe_id,
            'apps_plans_id' => $this->dto->apps_plans_id,
        ], [
            'amount' => $this->dto->amount,
            'currency' => $this->dto->currency,
            'interval' => $this->dto->interval,
            'is_default' => $this->dto->is_default,
        ]);
    }
}
