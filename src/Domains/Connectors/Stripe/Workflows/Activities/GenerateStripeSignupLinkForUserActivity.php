<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Workflows\Activities;

use Baka\Support\IPInfo;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Workflow\KanvasActivity;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Stripe;

class GenerateStripeSignupLinkForUserActivity extends KanvasActivity
{
    public $tries = 5;

    public function execute(UserInterface $user, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $stripeApiKey = $app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value);
        if (empty($stripeApiKey)) {
            return $this->errorResponse('Stripe is not configured for this app');
        }

        if (empty($params['ip'])) {
            return $this->errorResponse('IP address is required');
        }

        Stripe::setApiKey($stripeApiKey);

        $stripeUserId = $user->get(ConfigurationEnum::STRIPE_USER_ID->value);
        if (empty($stripeUserId)) {
            $stripeUserId = $this->createStripeAccount($user, $params['ip']);
        }

        $stripeAccount = Account::retrieve($stripeUserId);

        if (! empty($stripeAccount->charges_enabled)) {
            $user->set(ConfigurationEnum::STRIPE_ACCOUNT_CONNECTED->value, 1, true);
            $user->set(ConfigurationEnum::STRIPE_ACCOUNT_EMAIL->value, $stripeAccount->email);

            return $this->successResponse('Stripe account already connected');
        }

        $accountLink = $this->createAccountLink($user, $app);

        return $this->successResponse('Stripe account link generated', ['url' => $accountLink->url]);
    }

    private function createStripeAccount(UserInterface $user, string $ip): string
    {
        $ipInfo = new IPInfo();
        $ipAddressInfo = $ipInfo->getIpInfo($ip);
        $countryCode = $ipAddressInfo['country'] ?? 'US';
        $serviceAgreement = strtolower($countryCode) === 'us' ? 'full' : 'recipient';

        $account = Account::create([
            'country' => $countryCode,
            'type' => 'express',
            'capabilities' => [
                'transfers' => [
                    'requested' => true,
                ],
            ],
            'tos_acceptance' => [
                'service_agreement' => $serviceAgreement,
            ],
        ]);

        $user->set(ConfigurationEnum::STRIPE_USER_ID->value, $account->id);

        return $account->id;
    }

    private function createAccountLink(UserInterface $user, Apps $app): AccountLink
    {
        $displayName = preg_replace('/\s+/', '', $user->displayname);
        $baseUrl = $app->url . '/' . strtolower(Str::cleanup($displayName)) . '/settings';

        return AccountLink::create([
            'account' => $user->get(ConfigurationEnum::STRIPE_USER_ID->value),
            'refresh_url' => $baseUrl,
            'return_url' => $baseUrl . '/content-monetization', //@todo change
            'type' => 'account_onboarding',
        ]);
    }

    private function successResponse(string $message, array $data = []): array
    {
        return array_merge(['success' => true, 'message' => $message], $data);
    }

    private function errorResponse(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }
}
