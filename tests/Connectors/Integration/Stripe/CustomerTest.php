<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Actions\PushUserToStripeCustomerAction;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Stripe\StripeClient;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    public function testUserCustomerCreation()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        $stripe = new StripeClient($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));

        $customer = new PushUserToStripeCustomerAction(
            $user,
            $app,
            $company
        )->execute();

        $appUser = $user->getAppProfile($app);

        $this->assertNotEmpty($customer);
        $this->assertNotEmpty($customer->id);
        $this->assertEquals($customer->email, $user->email);
        $this->assertEquals($customer->name, $appUser->people->getName());
        $this->assertEquals($customer->id, $appUser->stripe_id);
    }
}
