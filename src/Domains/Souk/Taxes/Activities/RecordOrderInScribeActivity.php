<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Activities;

use Baka\Contracts\AppInterface;
use Baka\Contracts\BillableInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\SalesReceipts\Actions\CreateSalesReceiptAction;
use Kanvas\Scribe\SalesReceipts\Actions\VoidSalesReceiptAction;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceipt as SalesReceiptData;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceiptLine as SalesReceiptLineData;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Spatie\LaravelData\DataCollection;

#[WorkflowAction(
    name: 'Record Order in Scribe',
    description: 'Syncs a Souk order as a Sales Receipt (or Credit Note on refund) into the Scribe accounting domain. '
        . 'Opt-in per company via the scribe_sync_souk_orders setting. '
        . 'Idempotent: skips if a receipt with the same external_id already exists.',
)]
class RecordOrderInScribeActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        /** @var Order $order */
        $order = $entity;

        $company = $order->company;

        if (! $company->get(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value)) {
            return $this->failWorkflow([
                'message' => 'scribe sync disabled',
                'order_id' => $order->id,
            ]);
        }

        $refund = $params['refund'] ?? null;

        if ($refund !== null) {
            return $this->recordCreditNote($order, $app, $params);
        }

        return $this->recordSalesReceipt($order, $app);
    }

    private function recordSalesReceipt(Order $order, AppInterface $app): array
    {
        $externalId = $order->uuid;

        $existing = SalesReceipt::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $order->companies_id)
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            return [
                'message' => 'receipt already exists',
                'receipt_id' => $existing->id,
                'external_id' => $externalId,
            ];
        }

        $lines = $this->buildReceiptLines($order);

        $billable = $this->buildBillable($order);

        $receipt = new CreateSalesReceiptAction(
            data: new SalesReceiptData(
                app: $app,
                company: $order->company,
                billable: $billable,
                lines: new DataCollection(SalesReceiptLineData::class, $lines),
                receipt_date: Carbon::parse($order->created_at ?? now()),
                currency: $order->currency ?? 'USD',
                fx_rate_to_base: 1.0,
                external_id: $externalId,
                source: 'kanvas',
            ),
        )->execute();

        return [
            'message' => 'receipt recorded',
            'receipt_id' => $receipt->id,
            'external_id' => $externalId,
        ];
    }

    private function recordCreditNote(Order $order, AppInterface $app, array $params): array
    {
        /** @var PaymentRefund $refund */
        $refund = $params['refund'];
        $refundUuid = is_string($refund) ? $refund : $refund->uuid;

        $externalId = $order->uuid . '-r' . $refundUuid;

        $existing = SalesReceipt::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $order->companies_id)
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            return [
                'message' => 'credit note already exists',
                'receipt_id' => $existing->id,
                'external_id' => $externalId,
            ];
        }

        $original = SalesReceipt::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $order->companies_id)
            ->where('external_id', $order->uuid)
            ->first();

        if ($original === null) {
            return $this->failWorkflow([
                'message' => 'original receipt not found for credit note',
                'order_id' => $order->id,
            ]);
        }

        new VoidSalesReceiptAction(
            salesReceipt: $original,
            voidReasonCode: 'customer_refund',
        )->execute();

        return [
            'message' => 'credit note recorded (receipt voided)',
            'receipt_id' => $original->id,
            'external_id' => $externalId,
        ];
    }

    /**
     * @return SalesReceiptLineData[]
     */
    private function buildReceiptLines(Order $order): array
    {
        $order->loadMissing('allItems');
        $isImported = $order->tax_source === 'imported';
        $lines = [];

        if ($isImported) {
            $totalTax = (float) ($order->tax_amount ?? 0.0);
            $subtotal = (float) ($order->subtotal_amount ?? 0.0);

            $lines[] = new SalesReceiptLineData(
                description: 'Order #' . $order->id,
                quantity: 1.0,
                unit_price_native: $subtotal,
                tax_amount_native: $totalTax,
            );

            return $lines;
        }

        $taxByLine = $this->taxByLine($order);

        foreach ($order->allItems as $item) {
            $qty = (float) $item->quantity;
            $unitPriceNet = (float) $item->unit_price_net_amount;
            $lineTax = $taxByLine[$item->uuid] ?? 0.0;
            $taxRate = $unitPriceNet > 0 && $lineTax > 0
                ? round($lineTax / ($unitPriceNet * $qty), 6)
                : null;

            $lines[] = new SalesReceiptLineData(
                description: (string) $item->product_name,
                quantity: $qty,
                unit_price_native: $unitPriceNet,
                sku: $item->product_sku,
                tax_rate: $taxRate,
                tax_amount_native: $lineTax,
            );
        }

        $shippingPrice = (float) ($order->shipping_price_net_amount ?? 0.0);
        $shippingTax = $taxByLine['shipping'] ?? 0.0;

        if ($shippingPrice > 0 || $shippingTax > 0) {
            $lines[] = new SalesReceiptLineData(
                description: 'Shipping',
                quantity: 1.0,
                unit_price_native: $shippingPrice,
                tax_amount_native: $shippingTax,
            );
        }

        if (empty($lines)) {
            $lines[] = new SalesReceiptLineData(
                description: 'Order #' . $order->id,
                quantity: 1.0,
                unit_price_native: (float) ($order->subtotal_amount ?? 0.0),
            );
        }

        return $lines;
    }

    private function taxByLine(Order $order): array
    {
        $snapshot = OrderTaxSnapshot::query()
            ->where('order_id', $order->getId())
            ->with('components')
            ->latest('id')
            ->first();

        if ($snapshot === null) {
            return [];
        }

        return $snapshot->components
            ->groupBy('line_id')
            ->map(fn ($components) => round((float) $components->sum('tax_amount'), 4))
            ->all();
    }

    private function buildBillable(Order $order): BillableInterface
    {
        $displayName = $order->user_email
            ?? ($order->people?->name ?? 'Guest Customer');

        return new class ($order, $displayName) implements BillableInterface {
            public function __construct(
                private readonly Order $order,
                private readonly string $name,
            ) {
            }

            public function getBillableId(): int
            {
                return (int) ($this->order->people_id ?? $this->order->id);
            }

            public function getBillableDisplayName(): string
            {
                return $this->name;
            }

            public function getBillableLegalName(): ?string
            {
                return null;
            }

            public function getBillableTaxId(): ?string
            {
                return null;
            }

            public function getBillingEmail(): ?string
            {
                return $this->order->user_email;
            }

            public function getBillingAddressArray(): ?array
            {
                $addr = $this->order->billingAddress;

                if ($addr === null) {
                    return null;
                }

                return [
                    'address' => $addr->address,
                    'city' => $addr->city,
                    'state' => $addr->state,
                    'zip' => $addr->zip,
                    'country' => $addr->country?->code,
                ];
            }

            public function getDefaultPaymentTermsDays(): int
            {
                return 0;
            }

            public function getDefaultCurrency(): string
            {
                return $this->order->currency ?? 'USD';
            }
        };
    }
}
