<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Activities;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class DepositWalletActivity extends KanvasActivity
{
    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $userCompany = $order->getMetadata('user_company_id');
                if (! $userCompany) {
                    throw new Exception('User company not found in order metadata.');
                }

                $company = Companies::getById($userCompany);

                UsersRepository::belongsToThisApp(
                    $order->user,
                    $app,
                    $company
                );

                $wallet = $company->createAppWallet($app, ['name' => 'default']);
                $total = 0;
                foreach ($order->items() as $item) {
                    if ($item->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_TYPE_WALLET_COIN_SLUG->value)?->value === null) {
                        continue;
                    }

                    $total += $item->getTotal();
                }
                if ($total <= 0) {
                    throw new Exception('Total amount to deposit must be greater than zero.');
                }
                $wallet->depositFloat($total);
            },
            company: $order->company,
        );
    }
}
