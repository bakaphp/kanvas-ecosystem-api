<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Subscription\Prices\Models\Price;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Spatie\LaravelData\Data;

class SubscriptionInput extends Data
{
    public function __construct(
        public Companies $company,
        public AppInterface $app,
        public UserInterface $user,
        public string $payment_method_id,
        public Price $price,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        UserInterface $user,
        Companies $company,
        array $data,
    ): self {
        $price = PriceRepository::getByIdWithApp((int) $data['apps_plans_prices_id'], $app);

        return new self(
            company: $company,
            app: $app,
            user: $user,
            payment_method_id: (string) $data['payment_method_id'],
            price: $price,
        );
    }
}
