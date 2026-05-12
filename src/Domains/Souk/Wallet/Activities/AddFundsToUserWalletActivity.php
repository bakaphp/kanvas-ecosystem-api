<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\DispatchOrderReceiptAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToUserWalletAction;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use Kanvas\Souk\Wallet\Notifications\WalletRechargeConfirmedNotification;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class AddFundsToUserWalletActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $isAddingWalletFundTransaction = false;
        foreach ($order->items as $item) {
            if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_USER_WALLET_COIN_SLUG->value)?->value !== null) {
                $isAddingWalletFundTransaction = true;

                break; // Exit early since we found what we're looking for
            }
        }

        if (! $isAddingWalletFundTransaction) {
            return [
                'result' => false,
                'message' => 'No wallet fund transaction found in order items.',
                'order_id' => $order->getId(),
            ];
        }

        if (! $order->user) {
            return [
                'result' => false,
                'message' => 'User not found in order. Nothing to do',
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

                $transaction = new AddFundsToUserWalletAction(
                    order: $order,
                    source: TransactionSourceEnum::RECHARGE_MANUAL,
                )->execute($params['processTransactionsByWalletType'] ?? false);

                new DispatchOrderReceiptAction(
                    $order,
                    [
                        'template' => 'wallet-recharge-receipt',
                        'filename_pattern' => '{order_number}_recharge',
                        'activity_log' => 'WALLET_RECHARGE_RECEIPT_GENERATED',
                    ],
                    sync: true,
                )->execute();

                $order->user->notify(new WalletRechargeConfirmedNotification(
                    order: $order,
                    transaction: $transaction,
                    app: $app,
                    company: $order->company,
                ));

                return [
                    'result' => true,
                    'message' => 'Funds added to user wallet successfully.',
                    'order_id' => $order->getId(),
                    'transaction_id' => $transaction->getKey(),
                    'amount' => $transaction->amountFloat ?? 0,
                ];
            },
            company: $order->company,
        );
    }
}
