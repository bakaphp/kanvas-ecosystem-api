<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromAppleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;

class AppleInAppPurchaseMutation
{
    public function create(mixed $root, array $request): Order
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $defaultAppCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        try {
            $branch = CompaniesBranches::getById($defaultAppCompanyBranch);
            $company = $branch->company;
        } catch (ModelNotFoundException $e) {
            $company = $user->getCurrentCompany();
        }

        $region = Regions::getDefault($company, $app);

        $appleInAppPurchase = AppleInAppPurchaseReceipt::from(
            $app,
            $company,
            $user,
            $region,
            $request['input']
        );

        $createOrderFromInAppPurchase = new CreateOrderFromAppleReceiptAction($appleInAppPurchase);

        $order = $createOrderFromInAppPurchase->execute();

        if (! empty($appleInAppPurchase->custom_fields)) {
            $order->setCustomFields($appleInAppPurchase->custom_fields);
            $order->saveCustomFields();
        }

        /**
         * @todo move this to the create order DTO
         */
        $order->fireWorkflow(
            WorkflowEnum::AFTER_CREATE_ORDER->value,
            true,
            [
                'app' => $app,
            ]
        );

        return $order;
    }
}
