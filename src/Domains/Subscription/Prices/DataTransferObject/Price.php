<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class Price extends Data
{
    public function __construct(
        public AppInterface $app,
        public UserInterface $user,
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $interval = null,
        public ?string $apps_plans_id = null,
        public ?string $stripe_id = null,
        public ?bool $is_active = true,
        public ?bool $is_default = false
    ) {
    }

    /**
     * Create a new Price DTO from request data.
     */
    public static function viaRequest(array $request, UserInterface $user, AppInterface $app): self
    {
        return new self(
            $app,
            $user,
            $request['amount'] ?? null,
            $request['currency'] ?? null,
            $request['interval'] ?? null,
            $request['apps_plans_id'] ?? null,
            $request['stripe_id'] ?? null,
            $request['is_active'] ?? true,
            $request['is_default'] ?? false
        );
    }
}
