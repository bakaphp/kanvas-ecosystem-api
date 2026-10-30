<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Actions\SettleAuthorizedPaymentAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Jobs\RetryPaymentCaptureJob;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Settle Movipass Portal Payment',
    description: 'For an AUTHORIZED Portal payment on a Movipass order (any OrderTypeEnum type); paso_rapido orders are confirmed with Paso Rapido first, '
        . 'then the card is captured, the order marked paid and the receipt sent. The authorization is voided only '
        . 'when no service was delivered (Paso Rapido rejected or should_capture off); a failed capture of a '
        . 'confirmed service is retried by a job, never voided. This MOVES MONEY — attach it only to the '
        . 'after-payment-authorized event.',
    integration: IntegrationsEnum::ECHO_PAY,
)]
class SettlePortalPaymentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::ECHO_PAY,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $payment = $this->findSettleablePayment($order, (int) ($params['payment_id'] ?? 0));

                if (! $payment instanceof Payments) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'skipped',
                        'message' => 'No AUTHORIZED Portal payment of a Movipass order to settle',
                    ];
                }

                $result = new SettleAuthorizedPaymentAction($payment)->execute();

                if (! in_array($result['status'], ['success', 'skipped'], true)) {
                    return $this->failWorkflow($result);
                }

                return $result;
            },
            company: $order->company,
        );
    }

    private function findSettleablePayment(Model $order, int $paymentId): ?Payments
    {
        if (! $order instanceof Order || OrderTypeEnum::tryFrom((string) $order->orderType?->name) === null) {
            return null;
        }

        $payment = $order->payments()->whereKey($paymentId)->first();

        if (! $payment instanceof Payments || $payment->status !== PaymentStatusEnum::AUTHORIZED->value) {
            return null;
        }

        if (($payment->metadata[RetryPaymentCaptureJob::PENDING_KEY] ?? false) === true
            || ($payment->metadata[RetryPaymentCaptureJob::NEEDS_REVIEW_KEY] ?? false) === true) {
            return null;
        }

        return $payment->paymentMethod?->processor === SettleAuthorizedPaymentAction::PROCESSOR ? $payment : null;
    }
}
