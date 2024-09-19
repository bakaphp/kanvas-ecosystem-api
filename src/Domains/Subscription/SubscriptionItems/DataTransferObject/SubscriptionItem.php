<?php

declare(strict_types=1);

namespace Kanvas\Subscription\SubscriptionItems\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class SubscriptionItem extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public int $subscription_id,
        public string $stripe_id,
        public string $stripe_plan,
        public string $price_id,
        public ?int $quantity = 1,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user, CompanyInterface $company, AppInterface $app): self
    {
        return new self(
            $company,
            $app,
            $user,
            $request['subscription_id'],
            $request['stripe_id'],
            $request['stripe_plan'],
            $request['price_id'],
            $request['quantity'] ?? 1,
        );
    }
}
