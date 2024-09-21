<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Spatie\LaravelData\Data;

class SubscriptionPlan extends Data
{
    public function __construct(
        public CompaniesBranches $branch,
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $payment_method_id,
        public array $plan,
        public bool $is_active = true,
        public bool $is_cancelled = false,
        public bool $paid = false,
        public ?string $charge_date = null,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user, CompaniesBranches $branch, AppInterface $app): self
    {
        return new self(
            $branch,
            $app,
            $user,
            $request['name'],
            $request['payment_method_id'],
            $request['items'] ?? [],
        );
    }
}
