<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\BuildWalletTransactionMetaAction;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use RuntimeException;
use Throwable;

class BulkRechargeOrderTagsAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly AppInterface $app,
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

        $dni = (string) ($company->get('rnc') ?? '');
        $bankTransaction = $this->resolveBankTransaction();

        $service = null;
        $serviceError = null;

        try {
            $service = new PasoRapidoService($this->app, $company);
        } catch (Throwable $e) {
            report($e);
            $serviceError = $e->getMessage();
        }

        $results = [];

        foreach ($this->order->items as $item) {
            $tagNumber = (string) ($item->metadata['tag_number'] ?? '');

            if ($tagNumber === '') {
                continue;
            }

            $amount = (float) $item->unit_price_gross_amount;
            $fiscalCredit = (bool) ($item->metadata['fiscal_credit'] ?? false);

            if ($service === null) {
                $results[$tagNumber] = [
                    'status' => 'failed',
                    'amount' => $amount,
                    'error' => 'PasoRapido service unavailable: ' . $serviceError,
                    'order_item_id' => $item->getId(),
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

                $confirmResponse = $service->confirmPayment(new PaymentConfirmData(
                    reference: $tagNumber,
                    bankTransaction: $bankTransaction,
                    amount: $amount,
                    fiscalCredit: $fiscalCredit,
                    dni: $dni,
                ));

                if (! $confirmResponse->tag) {
                    throw new RuntimeException('PasoRapido did not confirm TAG ' . $tagNumber);
                }

                $telemetry = new UpdateVehicleTagTelemetryAction(
                    $this->order,
                    $this->app,
                    $tagNumber,
                    $amount,
                    $service,
                )->execute();

                $results[$tagNumber] = [
                    'status' => 'success',
                    'amount' => $amount,
                    'order_item_id' => $item->getId(),
                    'vehicle_telemetry' => $telemetry,
                ];
            } catch (Throwable $e) {
                report($e);

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
                ];
            }
        }

        $metadata = $this->order->metadata ?? [];
        $metadata['corporate_recharge_results'] = $results;
        $this->order->metadata = $metadata;
        $this->order->saveOrFail();

        $successCount = count(array_filter($results, fn ($r) => $r['status'] === 'success'));
        $failCount = count($results) - $successCount;

        return [
            'result' => true,
            'order_id' => $this->order->getId(),
            'total' => count($results),
            'success' => $successCount,
            'failed' => $failCount,
        ];
    }

    private function resolveBankTransaction(): string
    {
        $intentId = $this->order->get(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);

        if ($intentId) {
            return explode(':', (string) $intentId)[1] ?? (string) $intentId;
        }

        return 'wallet_bulk_' . $this->order->uuid;
    }
}
