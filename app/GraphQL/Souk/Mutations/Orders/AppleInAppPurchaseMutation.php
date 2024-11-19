<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromAppleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;

class AppleInAppPurchaseMutation
{
    public function create(mixed $root, array $request): Order
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $region = Regions::getDefault($company, $app);

        $appleInAppPurchase = AppleInAppPurchaseReceipt::from(
            $app,
            $company,
            $user,
            $region,
            $request['input']
        );

        $createOrderFromInAppPurchase = new CreateOrderFromAppleReceiptAction($appleInAppPurchase);

        return $createOrderFromInAppPurchase->execute();
    }
}
