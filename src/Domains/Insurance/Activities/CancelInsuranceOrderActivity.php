<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\RecordInsuranceStatusAction;
use Kanvas\Insurance\Actions\SettleInsurancePaymentAction;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs when the customer backs out before a policy exists. Nothing is sent to the
 * insurer — an unemitted quote simply lapses — so cancelling is releasing the hold.
 */
#[WorkflowAction]
class CancelInsuranceOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        /** @var Order $order */
        $status = (string) $order->get(InsuranceCustomFieldEnum::STATUS->value);

        if ($status === '') {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Order is not an insurance order',
            ];
        }

        if (in_array($status, [InsuranceStatusEnum::EMITTED->value, InsuranceStatusEnum::POLICY_ACTIVE->value], true)) {
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
