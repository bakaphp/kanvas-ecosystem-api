<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Support\RefundLedger;
use Kanvas\Connectors\BrushCrazy\Support\SiteBooking;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

/**
 * Books in BrushCrazy a refund made in Kanvas, so staff see it on the sale. BrushCrazy records it
 * with the Kanvas refund type and moves no money. A refund the mirror's refund job made is skipped:
 * BrushCrazy booked it first.
 */
#[WorkflowAction(
    name: 'BrushCrazy Push Refund',
    description: 'Records a Kanvas refund of a site order on its BrushCrazy sale (POST /api/kanvas/refunds). Hang it on Order after-refund.',
    integration: IntegrationsEnum::BRUSHCRAZY,
    requiresConfig: [ConfigurationEnum::BRUSHCRAZY_API_URL, ConfigurationEnum::BRUSHCRAZY_API_SECRET],
)]
class PushRefundToBrushCrazyActivity extends BrushCrazyPushActivity
{
    /**
     * @param Order $order
     */
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        return $this->runPush(
            $order,
            $app,
            $order->company,
            $params,
            function () use ($order, $app, $params) {
                if (! SiteBooking::wasPushed($order)) {
                    return $this->skip('Order was never booked in BrushCrazy.');
                }

                /** @var PaymentRefund|null $refund */
                $refund = PaymentRefund::query()
                    ->where('id', (int) ($params['payment_refund_id'] ?? 0))
                    ->whereIn('payments_id', $order->payments()->reorder()->select('id'))
                    ->first();

                if ($refund === null) {
                    return $this->failWorkflow(['message' => 'The refund does not belong to this order.', 'entity' => null]);
                }

                if (! $refund->isCompleted()) {
                    return $this->skip('The refund has not completed.');
                }

                if (RefundLedger::isMirrorRefund($order, $refund)) {
                    return $this->skip('Kanvas made this refund because BrushCrazy refunded it; nothing to push back.');
                }

                if (RefundLedger::wasPushed($order, (string) $refund->uuid)) {
                    return $this->skip('The refund was already recorded in BrushCrazy.');
                }

                $cents = SiteBooking::cents($refund->amount);
                $full = $this->isFullyRefunded($order);

                $body = [
                    'kanvas_order_uuid' => $order->uuid,
                    'kanvas_refund_id' => (string) $refund->uuid,
                    'amount_cents' => $cents,
                    'full' => $full,
                    'reason' => $refund->reason,
                ];

                return $this->post(
                    $app,
                    'api/kanvas/refunds',
                    $body,
                    function (array $response) use ($order, $refund, $cents, $full) {
                        RefundLedger::recordPushed(
                            $order,
                            $refund,
                            $cents,
                            $full,
                            $response
                        );

                        return [
                            'message' => 'Refund recorded in BrushCrazy.',
                            'status' => $response['status'] ?? null,
                            'response' => $response,
                            'entity' => $order,
                        ];
                    }
                );
            }
        );
    }

    private function isFullyRefunded(Order $order): bool
    {
        $payments = $order->payments()
            ->whereIn('status', [PaymentStatusEnum::PAID->value, PaymentStatusEnum::REVERSED->value])
            ->get();

        if ($payments->isEmpty()) {
            return false;
        }

        $captured = $payments->sum(fn (Payments $payment) => SiteBooking::cents($payment->amount));
        $refunded = $payments->sum(fn (Payments $payment) => RefundLedger::paymentRefundedCents($payment));

        return $refunded >= $captured;
    }
}
