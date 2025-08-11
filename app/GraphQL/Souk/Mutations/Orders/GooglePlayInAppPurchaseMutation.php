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
use Illuminate\Support\Facades\Log;

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

        if ($app->get('bypass_order_creation') && in_array($user->email, $app->get('bypass_order_creation_allowed_users'))) {
            Log::info("User {$user->email} is allowed to bypass validation.");
            //Get a valid order from the database as a result to let it bypass.
            return Order::fromApp($app)
                ->where('companies_id', $company->getId())
                ->where('status', 'completed')
                ->where("fulfillment_status", "fulfilled")
                ->firstOrFail();
        }

        $googleInAppPurchase = GooglePlayInAppPurchaseReceipt::from(
            $app,
            $company,
            $user,
            $region,
            $request['input']
        );

        $createOrderFromInAppPurchase = new CreateOrderFromGoogleReceiptAction($googleInAppPurchase);

        $order = $createOrderFromInAppPurchase->execute();

        if (! empty($appleInAppPurchase->custom_fields)) {
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
