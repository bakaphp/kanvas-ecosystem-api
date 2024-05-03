<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Users\Models\Users;
use Workflow\Activity;

class CreateUserActivity extends Activity
{
    public $tries = 1;

    public function execute(Users $user, Apps $app, array $params): void
    {

        $company = Companies::find($user->default_company);
        $defaultRegion = $company->regions->where('default', true)->first();
        $currency = $company->currency ?? Currencies::where('code', 'USD')->first();
        if (! $defaultRegion) {
            $dto = RegionDto::from([
                'company' => $company,
                'app' => $app,
                'user' => $user,
                'currency' => $currency,
                'name' => 'Default Region',
                'is_default' => 1,
                'short_slug' => 'default',
            ]);
            $defaultRegion = (new CreateRegionAction($dto, $user))->execute();
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
        $user->set('shopify_id', $customer['id']);
        $user->save();
    }
}
