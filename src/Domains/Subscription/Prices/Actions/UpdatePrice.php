<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price;

class UpdatePrice
{
    public function __construct(
        protected Price $price,
        protected PriceDto $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * Execute the action to update a price.
     *
     * @return Price
     */
    public function execute(): Price
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        $this->price->update([
            'amount' => $this->dto->amount,
            'currency' => $this->dto->currency,
            'interval' => $this->dto->interval,
            'is_default' => $this->dto->is_default,
        ]);

        return $this->price;
    }
}
