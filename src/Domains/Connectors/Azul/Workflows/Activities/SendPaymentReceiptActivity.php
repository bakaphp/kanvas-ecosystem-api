<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Azul Send Payment Receipt',
    description: 'Emails the customer a receipt for a paid order — card brand, last four, authorization code, '
        . 'date. This CONTACTS the customer. It only fires for an order with a paid payment and refuses '
        . 'to send twice, so a re-run does not double-mail them.',
    integration: IntegrationsEnum::AZUL,
)]
class SendPaymentReceiptActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public const TEMPLATE = 'user-payment-received';
    public const SENT_AT_KEY = 'payment_receipt_sent_at';

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::AZUL,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $skipReason = $this->getSkipReason($order, $additionalParams);

                if ($skipReason) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => $skipReason];
                }

                $payment = Payments::getLatestForEntity($order, [PaymentStatusEnum::PAID->value]);

                if (! $payment) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'No paid payment found'];
                }

                $paymentMethod = $payment->paymentMethod;

                new SendOrderEmailsAction(
                    $order,
                    self::TEMPLATE,
                    [
                        'payment' => $payment,
                        'payment_brand' => $paymentMethod?->payment_methods_brand ?? 'N/A',
                        'payment_last_four' => $paymentMethod?->payment_ending_numbers ?? '****',
                        'authorization_code' => $payment->authorization_code ?? 'N/A',
                        'transaction_type' => 'Compra',
                        'payment_date' => $payment->payment_date
                            ? Carbon::parse($payment->payment_date)->format('d/m/Y')
                            : Carbon::now()->format('d/m/Y'),
                        'processor' => $payment->processor ?? 'azul',
                    ],
                    ['mail'],
                    "Pago confirmado · Orden #{$order->order_number}",
                )->execute();

                $order->metadata = [
                    ...$order->metadata ?? [],
                    'data' => [
                        ...$order->metadata['data'] ?? [],
                        self::SENT_AT_KEY => Carbon::now()->toIso8601String(),
                    ],
                ];
                $order->saveQuietly();

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Payment receipt sent',
                    'data' => [
                        'payment_id' => $payment->getId(),
                        'template' => self::TEMPLATE,
                    ],
                ];
            },
            company: $order->company,
        );
    }

    private function getSkipReason(Order $order, array $additionalParams): ?string
    {
        $eventName = $additionalParams['currentEventTypeName'] ?? null;

        if ($eventName !== WorkflowEnum::UPDATED->value) {
            return 'Event is not an update event';
        }
        if ($order->payment_status !== PaymentStatusEnum::PAID->value) {
            return 'Order is not paid';
        }
        if (! empty($order->metadata['data'][self::SENT_AT_KEY] ?? null)) {
            return 'Payment receipt already sent';
        }

        return null;
    }
}
