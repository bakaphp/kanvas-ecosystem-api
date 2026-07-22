<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Wallet\Actions\BuildWalletTransactionMetaAction;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use RuntimeException;
use Throwable;

class BulkRechargeOrderTagsAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly AppInterface $app,
        protected readonly ?PasoRapidoService $injectedService = null,
    ) {
    }

    public function execute(): array
    {
        $company = $this->order->company;
        $wallet = $company->createAppWallet($this->app, ['name' => 'default']);

        $creditMeta = new BuildWalletTransactionMetaAction(
            source: TransactionSourceEnum::RECHARGE_MANUAL,
            actorUserId: $this->order->users_id,
            externalReference: $this->order->uuid,
            reason: 'Corporate bulk recharge funding',
            additional: [
                'service' => OrderTypeEnum::PASO_RAPIDO->value,
                'order_id' => $this->order->getId(),
                'is_bulk_recharge' => true,
            ],
        )->execute();

        $wallet->depositFloat((float) $this->order->total_gross_amount, $creditMeta);

        $dni = trim((string) ($company->get('rnc') ?? ''));

        if ($dni === '') {
            $dni = trim((string) ($this->order->metadata['data']['rnc'] ?? ''));
        }

        $bankTransaction = $this->resolveBankTransaction();
        // tener RNC implica querer credito fiscal
        $fiscalCredit = $dni !== '' || (bool) ($this->order->metadata['data']['fiscal_credit'] ?? false);

        $service = $this->injectedService;
        $serviceError = null;

        if ($service === null) {
            try {
                $service = new PasoRapidoService($this->app, $company);
            } catch (Throwable $e) {
                report($e);
                $serviceError = $e->getMessage();
            }
        }

        $results = [];

        foreach ($this->order->items as $item) {
            $tagNumber = (string) ($item->metadata['tag_number'] ?? '');

            if ($tagNumber === '') {
                continue;
            }

            $amount = (float) $item->unit_price_gross_amount;

            if ($service === null) {
                $results[$tagNumber] = [
                    'status' => 'failed',
                    'amount' => $amount,
                    'error' => 'PasoRapido service unavailable: ' . $serviceError,
                    'order_item_id' => $item->getId(),
                    'reversed' => true,
                ];

                continue;
            }

            $withdrawn = false;

            try {
                $debitMeta = new BuildWalletTransactionMetaAction(
                    source: TransactionSourceEnum::PAYMENT,
                    actorUserId: $this->order->users_id,
                    externalReference: "bulk:{$this->order->uuid}:{$tagNumber}",
                    reason: "Corporate bulk recharge TAG {$tagNumber}",
                    additional: [
                        'service' => OrderTypeEnum::PASO_RAPIDO->value,
                        'tag_number' => $tagNumber,
                        'order_id' => $this->order->getId(),
                        'order_item_id' => $item->getId(),
                    ],
                )->execute();

                $wallet->withdrawFloat($amount, $debitMeta);
                $withdrawn = true;

                $itemBankTransaction = $bankTransaction . '-' . $item->getId();

                $confirmResponse = $service->confirmPayment(new PaymentConfirmData(
                    reference: $tagNumber,
                    bankTransaction: $itemBankTransaction,
                    amount: $amount,
                    fiscalCredit: $fiscalCredit,
                    dni: $dni,
                ));

                if (! $confirmResponse->tag) {
                    throw new RuntimeException('PasoRapido did not confirm TAG ' . $tagNumber);
                }

                $invoiceArray = $confirmResponse->invoiceDetails->toArray();
                $item->metadata = [
                    ...$item->metadata ?? [],
                    'invoice' => $invoiceArray,
                ];
                $item->saveQuietly();

                $vehicle = $item->variant?->product;
                $telemetry = ['status' => 'skipped', 'reason' => 'item has no vehicle product'];

                if ($vehicle) {
                    $telemetry = new UpdateVehicleTagTelemetryAction(
                        $vehicle,
                        $this->order,
                        $this->app,
                        $tagNumber,
                        $amount,
                        $service,
                    )->execute();
                }

                $results[$tagNumber] = [
                    'status' => 'success',
                    'amount' => $amount,
                    'order_item_id' => $item->getId(),
                    'vehicle_telemetry' => $telemetry,
                    'invoice' => $invoiceArray,
                ];
            } catch (Throwable $e) {
                report($e);

                $reversedSuccessfully = ! $withdrawn;

                if ($withdrawn) {
                    try {
                        $wallet->depositFloat($amount, [
                            'source' => TransactionSourceEnum::REFUND_TECHNICAL->value,
                            'reason' => 'Reversal: PasoRapido error for TAG ' . $tagNumber,
                            'original_error' => $e->getMessage(),
                            'tag_number' => $tagNumber,
                            'order_id' => $this->order->getId(),
                            'order_item_id' => $item->getId(),
                        ]);
                        $reversedSuccessfully = true;
                    } catch (Throwable $reversalEx) {
                        report($reversalEx);
                        Log::error('BulkRechargeOrderTagsAction: reversal failed — manual intervention required', [
                            'tag' => $tagNumber,
                            'amount' => $amount,
                            'order_id' => $this->order->getId(),
                            'order_item_id' => $item->getId(),
                            'error' => $reversalEx->getMessage(),
                        ]);
                    }
                }

                $results[$tagNumber] = [
                    'status' => 'failed',
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                    'order_item_id' => $item->getId(),
                    'reversed' => $reversedSuccessfully,
                ];
            }
        }

        $successCount = count(array_filter($results, fn ($r) => $r['status'] === 'success'));
        $failCount = count($results) - $successCount;
        $originalTotalGrossAmount = (float) $this->order->total_gross_amount;

        $metadata = $this->order->metadata ?? [];
        $metadata['corporate_recharge_results'] = $results;
        $this->order->metadata = $metadata;
        $this->order->saveOrFail();

        $reversedItemIds = collect($results)
            ->filter(fn ($r) => $r['status'] === 'failed' && ($r['reversed'] ?? false) === true)
            ->pluck('order_item_id')
            ->filter()
            ->all();

        if (! empty($reversedItemIds)) {
            OrderItem::query()
                ->whereIn('id', $reversedItemIds)
                ->update(['is_deleted' => 1]);
        }

        $liveItems = $this->order->items()->where('is_deleted', 0)->get();
        $newTotalGrossAmount = (float) $liveItems->sum(
            fn ($item) => (float) $item->unit_price_gross_amount * (int) $item->quantity,
        );

        if ($newTotalGrossAmount !== $originalTotalGrossAmount) {
            $metadata['data'] = $metadata['data'] ?? [];
            $metadata['data']['original_total_gross_amount'] = $originalTotalGrossAmount;
            $metadata['data']['adjusted_at'] = Carbon::now()->toIso8601String();
            $metadata['data']['adjusted_reason'] = 'bulk_recharge_partial_failure';
            $this->order->metadata = $metadata;
        }

        $this->order->total_gross_amount = $newTotalGrossAmount;

        $liveItemCount = (int) $this->order->items()->where('is_deleted', 0)->count();

        if ($liveItemCount === 0) {
            $this->order->status = 'canceled';
            $this->order->payment_status = 'refunded';
            $this->order->fulfillment_status = 'canceled';
        } else {
            $this->order->fulfillment_status = $failCount === 0 ? 'fulfilled' : 'partial';
        }

        $this->order->saveOrFail();

        return [
            'result' => true,
            'order_id' => $this->order->getId(),
            'total' => count($results),
            'success' => $successCount,
            'failed' => $failCount,
            'original_total_gross_amount' => $originalTotalGrossAmount,
            'adjusted_total_gross_amount' => $newTotalGrossAmount,
        ];
    }

    private function resolveBankTransaction(): string
    {
        $intentId = $this->order->get(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);

        if ($intentId) {
            return explode(':', (string) $intentId)[1] ?? (string) $intentId;
        }

        // PasoRapido transaccionBanco max length = 50. Per-item suffix `-{id}` adds ~10 more,
        // so cap the order prefix at 22 chars (BR + 20 hex from uuid, no separators).
        return 'BR' . substr(str_replace('-', '', (string) $this->order->uuid), 0, 20);
    }
}
