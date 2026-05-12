<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\DispatchOrderReceiptAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToCompanyWalletAction;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class AddFundsToWalletActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $isAddingWalletFundTransaction = false;
        foreach ($order->items as $item) {
            if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value !== null) {
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

                $transaction = new AddFundsToCompanyWalletAction(
                    order: $order,
                    source: TransactionSourceEnum::RECHARGE_MANUAL,
                )->execute();

                new DispatchOrderReceiptAction(
                    $order,
                    [
                        'template' => 'wallet-recharge-receipt',
                        'filename_pattern' => '{order_number}_recharge',
                        'activity_log' => 'WALLET_RECHARGE_RECEIPT_GENERATED',
                    ],
                    sync: true,
                )->execute();

                // @todo wire WalletRechargeConfirmedNotification for company-wallet path once a
                // clean "company contact user" surface is available. Companies::user() returns the
                // owner but the notification needs a notifiable Users instance; skip for now to
                // avoid guessing the intended recipient.

                return [
                    'result' => true,
                    'message' => 'Funds added to wallet successfully.',
                    'order_id' => $order->getId(),
                    'transaction_id' => $transaction->getKey(),
                    'amount' => $transaction->amountFloat ?? 0,
                ];
            },
            company: $order->company,
        );
    }
}
