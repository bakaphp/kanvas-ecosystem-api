<?php
declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Workflows\Activities\AssignPlanWithFreeTrialActivity;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Tests\TestCase;

final class AssignPlanWithFreeTrialActivityTest extends TestCase
{
    protected Apps $app;
    protected UserInterface $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = app(Apps::class);
        $this->user = auth()->user();
        $company = $this->user->getCurrentCompany();

        if (empty($this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            $this->app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        }
    }

    public function testAssignPlanWithFreeTrial(): void
    {
        $assignPlanActivity = new AssignPlanWithFreeTrialActivity();

        $result = $assignPlanActivity->execute(
            app: $this->app,
            user: $this->user,
            params: []
        );

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Plan assigned successfully. Trial period started.', $result['message']);
    }

    public function testValidateStripeThrowsException(): void
    {
        $this->expectException(ValidationException::class);

        $this->app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, null);

        $assignPlanActivity = new AssignPlanWithFreeTrialActivity();
        $assignPlanActivity->execute($this->app, $this->user, []);
    }
}