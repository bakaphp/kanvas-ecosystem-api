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
use Kanvas\Souk\Wallet\Actions\AddFundsToUserWalletAction;
use Kanvas\Souk\Wallet\Actions\PayFromWalletAction;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
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

        $log = activity('create-order-from-cart')
            ->causedBy($user)
            ->withProperties([
                'request_data' => $request,
                'user_id' => $user->id,
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
            ])
            ->log('IAP Order Creation Initiated');

        $appleInAppPurchase = AppleInAppPurchaseReceipt::from(
            $app,
            $company,
            $user,
            $region,
            $request['input']
        );
        $createOrderFromInAppPurchase = new CreateOrderFromAppleReceiptAction($appleInAppPurchase);

        $order = $createOrderFromInAppPurchase->execute();

        $log->subject_type = get_class($order);
        $log->subject_id = $order->id;
        $log->description = 'IAP Order Created Successfully';
        $log->save();

        if (! empty($appleInAppPurchase->custom_fields)) {
            $order->setCustomFields($appleInAppPurchase->custom_fields);
            $order->saveCustomFields();
        }

        $this->processWalletTransaction($order);

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

    protected function processWalletTransaction(Order $order): void
    {
        $walletTypes = [
        ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value,
        ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_CONSUME->value,
    ];

        foreach ($order->items as $item) {
            foreach ($walletTypes as $type) {
                $attributeValue = $item->variant->getAttributeBySlug($type)?->value;

                if ($attributeValue === null) {
                    continue;
                }

                if (in_array($attributeValue, $walletTypes, true)) {
                    match ($attributeValue) {
                        ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value => (new AddFundsToUserWalletAction($order))->execute(), //@todo also support company?
                        ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_CONSUME->value => (new PayFromWalletAction($order))->execute(),
                        default => null
                    };

                    return; // Exit the entire method after first wallet operation
                }
            }
        }
    }
}
