<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\MoveOrderToCompanyAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
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
                    $previousNumber = $order->order_number;
                    new MoveOrderToCompanyAction($order, $userCompany)->execute();
                    $order->refresh();
                    $conflict = $order->order_number !== $previousNumber;

                    new SendUserNotificationAction(
                        $order->app,
                        $order->company,
                        $order->user
                    )->execute('admin-new-order', [
                        'order' => $order,
                    ]);

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
