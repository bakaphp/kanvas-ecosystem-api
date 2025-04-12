<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People as ModelsPeople;
use Stripe\StripeClient;

class StripeCustomerService
{
    protected StripeClient $stripe;

    public function __construct(
        protected AppInterface $app,
    ) {
        $this->stripe = new StripeClient($this->app->get('stripe_secret_key'));
    }

    public function getOrCreateCustomerByPerson(ModelsPeople $people): \Stripe\Customer
    {
        $email = $people->getEmails()->first()->value ?? '';
        if (empty($email)) {
            throw new ValidationException('Email is required to create a Stripe customer');
        }
        $name = $people->name;

        // Optional: check if you already saved stripe_customer_id in your DB
        if (! empty($people->get(CustomFieldEnum::STRIPE_ID->value))) {
            return $this->stripe->customers->retrieve(
                $people->get(CustomFieldEnum::STRIPE_ID->value),
                []
            );
        }

        $existingCustomers = $this->stripe->customers->all([
            'email' => $email,
            'limit' => 1,
        ]);

        if (count($existingCustomers->data) > 0) {
            $customer = $existingCustomers->data[0];
        } else {
            // 👤 Create new customer
            $customer = $this->stripe->customers->create([
                'email' => $email,
                'name' => $name,
            ]);

            $people->set(CustomFieldEnum::STRIPE_ID->value, $customer->id);
        }

        return $customer;
    }
}
