<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Support\SiteBooking;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

#[WorkflowAction(
    name: 'BrushCrazy Push Paid Order',
    description: 'Books a paid site order in BrushCrazy (POST /api/kanvas/sales) so staff see the sale and its registrations. Hang it on Order after-payment-intent.',
    integration: IntegrationsEnum::BRUSHCRAZY,
    requiresConfig: [ConfigurationEnum::BRUSHCRAZY_API_URL, ConfigurationEnum::BRUSHCRAZY_API_SECRET],
)]
class PushPaidOrderToBrushCrazyActivity extends BrushCrazyPushActivity
{
    /**
     * @param Order $order
     */
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        return $this->runPush($order, $app, $order->company, $params, function () use ($order, $app) {
            if (SiteBooking::isMirroredOrder($order)) {
                return $this->skip('Order came from the BrushCrazy mirror; nothing to push back.');
            }

            if (SiteBooking::wasPushed($order)) {
                return $this->skip('Order was already booked in BrushCrazy.');
            }

            if (! SiteBooking::isSiteOrder($order)) {
                return $this->failWorkflow([
                    'message' => 'Not a site event order (no brushcrazy.event-order.v1 metadata or bc-event: reference).',
                    'entity' => null,
                ]);
            }

            [$booking, $reason] = SiteBooking::resolve($order);

            if ($booking === null) {
                return $this->failWorkflow(['message' => $reason, 'entity' => null]);
            }

            $lines = $booking->seatLines();

            if ($lines === []) {
                return $this->failWorkflow(['message' => 'The order has no seat lines.', 'entity' => null]);
            }

            return $this->post($app, 'api/kanvas/sales', $this->body($booking, $lines), function (array $response) use ($order) {
                SiteBooking::stampPushed($order, $response);

                return [
                    'message' => 'Order booked in BrushCrazy.',
                    'sale_id' => $response['sale_id'] ?? null,
                    'response' => $response,
                    'entity' => $order,
                ];
            });
        });
    }

    private function body(SiteBooking $booking, array $lines): array
    {
        $order = $booking->order;
        $subtotal = array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_cents'], $lines));
        $discount = SiteBooking::cents($order->discount_amount);
        $tax = SiteBooking::cents($order->tax_amount);
        $total = $subtotal - $discount + $tax;

        /** @var Payments|null $payment */
        $payment = $order->payments()->where('status', PaymentStatusEnum::PAID->value)->first();

        return [
            'kanvas_order_uuid' => $order->uuid,
            'studio_id' => $booking->studioId,
            'customer' => $booking->customer(),
            'paid_at' => ($order->paid_at ?? $payment?->created_at ?? now())->toIso8601String(),
            'currency' => strtoupper((string) ($order->currency ?: 'USD')),
            'totals' => [
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'discount_cents' => $discount,
                'total_cents' => $total,
            ],
            'payment' => [
                'reference' => (string) ($payment?->payment_intent_id ?: $payment?->authorization_code ?: $payment?->uuid ?: $order->uuid),
                'method' => (string) ($payment?->processor ?: 'kanvas'),
                'amount_cents' => $payment !== null ? SiteBooking::cents($order->getPaidAmount()) : $total,
            ],
            'lines' => $lines,
        ];
    }
}
