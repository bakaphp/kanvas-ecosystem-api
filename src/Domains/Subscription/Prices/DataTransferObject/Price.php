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
        public int $apps_plans_id,
        public string $stripe_id,
        public float $amount,
        public string $currency,
        public string $interval,
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
            $request['apps_plans_id'],
            $request['stripe_id'],
            $request['amount'],
            $request['currency'],
            $request['interval'],
            $request['is_active'] ?? true,
            $request['is_default'] ?? false
        );
    }
}
