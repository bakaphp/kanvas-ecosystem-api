<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Plans\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Spatie\LaravelData\Data;

class Plan extends Data
{
    public function __construct(
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public ?string $description = null,
        public string $stripe_id,
        public bool $is_default = false,
        public bool $is_deleted = false,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user, AppInterface $app): self
    {
        return new self(
            $app,
            $user,
            $request['name'],
            $request['stripe_id'],
            $request['description'] ?? null,
            $request['is_default'] ?? false,
            $request['is_deleted'] ?? false
        );
    }
}