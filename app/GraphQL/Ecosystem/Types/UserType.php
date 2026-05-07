<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Types;

use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Users\Models\Users;
use Laravel\Cashier\Subscription;

class UserType
{
    /**
     * Return the user address formatted for the response type.
     */
    public function address(Users $user, array $request): Users
    {
        $user->address = [
            'address_1' => $user->address_1,
            'address_2' => $user->address_2,
            'zip_code' => $user->zip_code,
        ];

        return $user;
    }

    /**
     * Return the user contact info formatted for the response type.
     */
    public function contact(Users $user, array $request): array
    {
        $app = app(Apps::class);

        return [
            'phone_number' => $user->phone_number,
            'cell_phone_number' => $user->cell_phone_number,
            'two_step_phone_number' => $user->getAppProfile($app)->two_step_phone_number,
        ];
    }

    /**
     * Return the latest subscription for the user in the current app.
     * User subscriptions use companies_id = 0 to distinguish from company subscriptions.
     */
    public function currentSubscription(Users $user, array $request): ?Subscription
    {
        $app = app(Apps::class);

        $stripeCustomer = AppsStripeCustomer::where('users_id', $user->getId())
            ->where('companies_id', 0)
            ->where('apps_id', $app->getId())
            ->first();

        if (! $stripeCustomer) {
            return null;
        }

        return $stripeCustomer->subscriptions()->latest()->first();
    }
}
