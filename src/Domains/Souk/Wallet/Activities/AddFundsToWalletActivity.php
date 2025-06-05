<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToWalletAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class AddFundsToWalletActivity extends KanvasActivity
{
    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

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
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                return new AddFundsToWalletAction(
                    order: $order,
                )->execute();
            },
            company: $order->company,
        );
    }
}
