<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Actions\AssignImpoundOrderToPayerAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Movipass: assign paid retention to its payer',
    description: 'On a paid vehicle retention (impound_lot) order, moves the order owner and customer to the user who paid it.',
)]
class AssignImpoundOrderToPayerActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (! $order instanceof Order) {
            return ['status' => 'skipped', 'message' => 'Not an order'];
        }

        $changes = new AssignImpoundOrderToPayerAction($order)->execute();

        if ($changes === null) {
            return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'Not a paid retention, or already owned by its payer'];
        }

        return ['order' => $order->getId(), 'status' => 'success', ...$changes];
    }
}
