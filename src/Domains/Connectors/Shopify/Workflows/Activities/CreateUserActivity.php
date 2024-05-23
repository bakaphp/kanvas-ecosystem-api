<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Workflow\Activity;

class CreateUserActivity extends Activity
{
    public $tries = 5;

    public function execute(Users $user, Apps $app, array $params): array
    {
        if (! isset($params['company']) || ! $params['company'] instanceof Companies) {
            return [
                'status' => 'error',
                'message' => 'Company is required',
                'user' => $user->getId(),
            ];
        }

        $company = $params['company'];
        $defaultRegion = Regions::getDefault($company);
        $currency = $company->currency ?? Currencies::where('code', 'USD')->first();

        if (! $defaultRegion) {
            return [
                'status' => 'error',
                'message' => 'Default region not found',
                'user' => $user->getId(),
                'company' => $company->getId(),
            ];
        }

        $client = Client::getInstance($app, $company, $defaultRegion);
        $customer = [
            'first_name' => $user->firstname,
            'last_name' => $user->lastname,
            'email' => $user->email,
            'phone' => $user->phone,
            'verified_email' => true,
            'send_email_invite' => false,
            'password' => $params['password'],
            'password_confirmation' => $params['password'],
        ];

        $customer = $client->Customer->post($customer);
        $user->set(CustomFieldEnum::USER_SHOPIFY_ID->value, $customer['id']);

        return [
            'status' => 'success',
            'message' => 'Customer created successfully',
            'shopify_id' => $customer['id'],
            'user' => $user->getId(),
            'company' => $company->getId(),
        ];
    }
}
