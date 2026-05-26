<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Subscription\Plans\Models\Plan as PlanModel;
use Spatie\LaravelData\Data;

class Plan extends Data
{
    public function __construct(
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $stripe_id,
        public ?string $description = null,
        public int $free_trial_dates = 0,
        public bool $is_active = true,
        public bool $is_default = false,
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
            name: (string) $data['name'],
            stripe_id: (string) $data['stripe_id'],
            description: $data['description'] ?? null,
            free_trial_dates: (int) ($data['free_trial_dates'] ?? 0),
            is_active: (bool) ($data['is_active'] ?? true),
            is_default: (bool) ($data['is_default'] ?? false),
        );
    }

    public static function forUpdate(
        PlanModel $plan,
        AppInterface $app,
        UserInterface $user,
        array $data,
    ): self {
        return new self(
            app: $app,
            user: $user,
            name: (string) ($data['name'] ?? $plan->name),
            stripe_id: $plan->stripe_id,
            description: $data['description'] ?? $plan->description,
            free_trial_dates: (int) ($data['free_trial_dates'] ?? $plan->free_trial_dates),
            is_active: (bool) ($data['is_active'] ?? $plan->is_active),
            is_default: (bool) ($data['is_default'] ?? $plan->is_default),
        );
    }
}
