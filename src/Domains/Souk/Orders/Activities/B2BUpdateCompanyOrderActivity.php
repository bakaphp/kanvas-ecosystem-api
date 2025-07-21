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

                sleep(30);

                $order->refresh();

                if ($orderCompany->getId() !== $userCompany->getId()) {
                    // Check for conflict
                    $conflict = Order::fromApp($app)
                        ->where('companies_id', $userCompany->getId())
                        ->where('order_number', $order->order_number)
                        ->where('id', '!=', $order->id)
                        ->exists();

                    // If conflict exists, generate a new order number
                    if ($conflict) {
                        $order->companies_id = $userCompany->getId();
                        $order->order_number = $order->generateOrderNumber(); // Uses lockForUpdate to avoid race
                    } else {
                        $order->companies_id = $userCompany->getId();
                    }

                    $order->updateOrFail();

                    return [
                        'result' => true,
                        'message' => $conflict
                            ? 'Order company updated with new order number due to conflict.'
                            : 'Order company updated successfully.',
                        'order_id' => $order->getId(),
                        'new_company_id' => $userCompany->getId(),
                        'old_company_id' => $orderCompany->getId(),
                        'new_order_number' => $order->order_number,
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
