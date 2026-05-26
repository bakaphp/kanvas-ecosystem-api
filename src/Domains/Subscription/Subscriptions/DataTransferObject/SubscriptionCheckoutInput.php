<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Subscription\Prices\Models\Price;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Spatie\LaravelData\Data;

class SubscriptionCheckoutInput extends Data
{
    public function __construct(
        public Companies $company,
        public AppInterface $app,
        public UserInterface $user,
        public Price $price,
        public ?string $success_url = null,
        public ?string $cancel_url = null
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user, Companies $company, AppInterface $app): self
    {
        $price = PriceRepository::getByIdWithApp((int) $request['apps_plans_prices_id'], $app);

        return new self(
            $company,
            $app,
            $user,
            $price,
            $request['success_url'] ?? null,
            $request['cancel_url'] ?? null
        );
    }
}
