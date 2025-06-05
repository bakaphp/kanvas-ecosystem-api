<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToWalletAction;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class AddFundsToWalletActivity extends KanvasActivity
{
    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $isAddingWalletFundTransaction = false;
        foreach ($order->items as $item) {
            if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value === null) {
                continue;
            }
            $isAddingWalletFundTransaction = true;
        }

        $userCompany = $order->getMetadata('user_company_id');

        if (! $userCompany) {
            return [
                'result' => false,
                'message' => 'User company not found in order metadata. Nothing to do',
                'order_id' => $order->getId(),
            ];
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params, $isAddingWalletFundTransaction) {
                if (! $isAddingWalletFundTransaction) {
                    return [
                        'result' => false,
                        'message' => 'No wallet fund transaction found in order items.',
                        'order_id' => $order->getId(),
                    ];
                }

                return new AddFundsToWalletAction(
                    order: $order,
                )->execute();
            },
            company: $order->company,
        );
    }
}
