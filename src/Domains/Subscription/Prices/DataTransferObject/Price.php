<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Prices\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Subscription\Prices\Models\Price as PriceModel;
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
        public ?bool $is_default = false,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        UserInterface $user,
        array $data,
    ): self {
        return new self(
            app: $app,
            user: $user,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            interval: $data['interval'] ?? null,
            apps_plans_id: isset($data['apps_plans_id']) ? (string) $data['apps_plans_id'] : null,
            stripe_id: $data['stripe_id'] ?? null,
            is_active: (bool) ($data['is_active'] ?? true),
            is_default: (bool) ($data['is_default'] ?? false),
        );
    }

    public static function forUpdate(
        PriceModel $price,
        AppInterface $app,
        UserInterface $user,
        array $data,
    ): self {
        return new self(
            app: $app,
            user: $user,
            amount: isset($data['amount']) ? (float) $data['amount'] : $price->amount,
            currency: $data['currency'] ?? $price->currency,
            interval: $data['interval'] ?? $price->interval,
            apps_plans_id: (string) $price->apps_plans_id,
            stripe_id: $price->stripe_id,
            is_active: (bool) ($data['is_active'] ?? $price->is_active),
            is_default: (bool) ($data['is_default'] ?? $price->is_default),
        );
    }
}
