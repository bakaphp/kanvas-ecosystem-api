<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Services\StripeCustomerService;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Users\Models\Users;
use Stripe\Customer;

class PushUserToStripeCustomerAction
{
    public function __construct(
        protected Users $user,
        protected AppInterface $app,
        protected Companies $company
    ) {
    }

    public function execute(): Customer
    {
        $stripeCustomerService = new StripeCustomerService($this->app);

        $people = new CreatePeopleFromUserAction(
            $this->app,
            $this->company->defaultBranch,
            $this->user,
        )->execute();

        $stripeCustomer = $stripeCustomerService->getOrCreateCustomerByPerson($people);

        $userApp = $this->user->getAppProfile($this->app);
        if ($stripeCustomer->id !== $userApp->stripe_customer_id) {
            $userApp->update(['stripe_customer_id' => $stripeCustomer->id]);
        }

        return $stripeCustomer;
    }
}
