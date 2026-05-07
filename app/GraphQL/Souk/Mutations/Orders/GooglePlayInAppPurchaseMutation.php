<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromGoogleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;

class GooglePlayInAppPurchaseMutation
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

        $log = activity('create-order-from-android-iap')
                 ->causedBy($user)
                 ->withProperties([
                     'request_data' => $request,
                     'user_id' => $user->id,
                     'apps_id' => $app->getId(),
                     'companies_id' => $company->getId(),
                 ])
                 ->log('IAP Order Creation Initiated');

        $googleInAppPurchase = GooglePlayInAppPurchaseReceipt::from(
            $app,
            $company,
            $user,
            $region,
            $request['input']
        );

        $createOrderFromInAppPurchase = new CreateOrderFromGoogleReceiptAction($googleInAppPurchase);

        $order = $createOrderFromInAppPurchase->execute();

        $log->subject_type = get_class($order);
        $log->subject_id = $order->id;
        $log->description = 'IAP Order Created Successfully';
        $log->save();

        if (! empty($googleInAppPurchase->custom_fields)) {
            $order->setCustomFields($googleInAppPurchase->custom_fields);
            $order->saveCustomFields();
        }

        /**
         * @todo move this to the create order Action
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
