<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class B2BUpdateCompanyOrderActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $userCompany = $order->user->getCurrentCompany();
                $orderCompany = $order->company;

                sleep(10);

                if ($orderCompany->getId() !== $userCompany->getId()) {
                    $order->companies_id = $userCompany->getId();
                    $order->saveOrFail();

                    return [
                        'result' => true,
                        'message' => 'Order company updated successfully.',
                        'order_id' => $order->getId(),
                        'new_company_id' => $userCompany->getId(),
                        'old_company_id' => $orderCompany->getId(),
                    ];
                }

                return [
                    'result' => false,
                    'message' => 'Order company is already the same as the user company.',
                    'order_id' => $order->getId(),
                    'company_id' => $userCompany->getId(),
                ];
            },
            company: $order->company,
        );
    }
}
