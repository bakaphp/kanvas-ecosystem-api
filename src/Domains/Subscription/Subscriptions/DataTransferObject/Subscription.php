<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class Subscription extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $stripe_id,
        public ?string $payment_method_id,
        public array $items,
        public bool $is_active = true,
        public bool $is_cancelled = false,
        public bool $paid = false,
        public ?string $charge_date = null,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user, CompanyInterface $company, AppInterface $app): self
    {
        return new self(
            $company,
            $app,
            $user,
            $request['name'],
            $request['stripe_id'],
            $request['payment_method_id'],
            $request['items'] ?? [],
        );
    }
}