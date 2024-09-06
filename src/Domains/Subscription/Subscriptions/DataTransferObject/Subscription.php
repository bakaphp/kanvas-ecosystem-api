<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class Subscription extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $stripe_id,
        public int $plan_id,
        public bool $is_active = true,
        public bool $is_cancelled = false,
        public bool $paid = false,
        public ?string $charge_date = null,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user): self
    {
        return new self(
            isset($request['company_id']) ? Companies::getById($request['company_id']) : $user->getCurrentCompany(),
            app(Apps::class),
            auth()->user,
            $request['name'],
            $request['stripe_id'],
            $request['plan_id'],
            $request['is_active'] ?? true,
            $request['is_cancelled'] ?? false,
            $request['paid'] ?? false,
            $request['charge_date'] ?? null,
        );
    }
}