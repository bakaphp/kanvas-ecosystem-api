<?php

declare(strict_types=1);

namespace Kanvas\Subscription\Subscriptions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;
use Stripe\Subscription as StripeSubscription;

class Subscription extends Data
{
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public UserInterface $user,
        public string $name,
        public string $stripe_id,
        public string $stripe_plan,
        public ?string $payment_method_id,
        public bool $is_active = true,
        public bool $is_cancelled = false,
        public bool $paid = false,
        public ?string $charge_date = null,
    ) {
    }

    public static function viaRequest(array $request, UserInterface $user, StripeSubscription $stripeSubscription = null): self
    {
        $stripe_id = $stripeSubscription ? $stripeSubscription->id : ($request['stripe_id'] ?? '');
        $is_active = $stripeSubscription ? $stripeSubscription->status === 'active' : ($request['is_active'] ?? true);
        $is_cancelled = $stripeSubscription ? $stripeSubscription->status === 'canceled' : ($request['is_cancelled'] ?? false);
        $charge_date = $stripeSubscription ? date('Y-m-d H:i:s', $stripeSubscription->current_period_end) : ($request['charge_date'] ?? null);

        return new self(
            Companies::getById($request['companies_id']) ?? $user->getCurrentCompany(),
            app(Apps::class),
            $user,
            $request['name'],
            $stripe_id,
            $request['stripe_plan'],
            $request['payment_method_id'],
            $is_active,
            $is_cancelled,
            $request['paid'] ?? false,
            $charge_date,
        );
    }
}