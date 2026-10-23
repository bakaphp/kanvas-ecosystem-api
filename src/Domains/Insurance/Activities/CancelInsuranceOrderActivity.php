<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\RecordInsuranceStatusAction;
use Kanvas\Insurance\Actions\SettleInsurancePaymentAction;
use Kanvas\Insurance\Enums\InsuranceOrderOriginEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Services\InsuranceStatus;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Insurance Cancel Order',
    description: 'Cancels an insurance order that has no policy yet: voids the held card payment and records the cancelled insurance status. '
        . 'Use it when the order is cancelled or the customer backs out before emission. It refuses once a policy exists (emitted, active or expired) and returns a failed step, because an issued policy cannot be cancelled here. '
        . 'It releases the customer\'s hold but does not notify the customer or the insurer.',
)]
class CancelInsuranceOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        if (InsuranceOrderOriginEnum::isRegistered($order)) {
            return InsuranceOrderOriginEnum::purchaseFlowSkipped($order);
        }

        $status = InsuranceStatus::of($order);

        if ($status === null) {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Order is not an insurance order',
            ];
        }

        if (in_array($status, [InsuranceStatusEnum::EMITTED, InsuranceStatusEnum::POLICY_ACTIVE, InsuranceStatusEnum::EXPIRED], true)) {
            return $this->failWorkflow([
                'order' => $order->getId(),
                'message' => 'A policy already exists for this order; it cannot be cancelled here',
            ]);
        }

        $released = new SettleInsurancePaymentAction($order)->release();
        new RecordInsuranceStatusAction($order, InsuranceStatusEnum::CANCELLED)->execute();

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'paymentReleased' => $released === true,
        ];
    }
}
