<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Workflows\Activities;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

class GenerateStripeSignupLinkForUserActivity extends KanvasActivity
{
    public $tries = 5;

    /**
     * @todo move to middleware
     */
    public function validateStripe(Apps $app)
    {
        if (empty($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            //throw new ValidationException('Stripe is not configured for this app');
        }
    }

    public function execute(UserInterface $user, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $this->validateStripe($app);
        $company = $user->getCurrentCompany();
        $response = [
            'test'
        ];

       // print_r($response);
        //$companyStripeAccount = $company->getStripeAccount($app);

        
        return $response;
    }
}
