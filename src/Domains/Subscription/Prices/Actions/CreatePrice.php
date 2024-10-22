<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price;

class CreatePrice
{
    public function __construct(
        protected PriceDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * Execute the action to create or retrieve an existing price.
     *
     * @return Price
     */
    public function execute(): Price
    {
        return Price::firstOrCreate([
            'stripe_id' => $this->dto->stripe_id,
            'apps_plans_id' => $this->dto->apps_plans_id,
        ], [
            'amount' => $this->dto->amount,
            'currency' => $this->dto->currency,
            'interval' => $this->dto->interval,
            'is_default' => $this->dto->is_default,
        ]);
    }
}
