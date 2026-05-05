<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EchoPayCustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\BuildWalletTransactionMetaAction;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use RuntimeException;
use Throwable;

class BulkRechargeTagsActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 1;

    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                /** @var Order $order */
                if ($order->orderType?->name !== OrderTypeEnum::PASO_RAPIDO->value) {
                    return ['skipped' => true, 'reason' => 'order is not paso_rapido'];
                }

                if (($order->metadata['data']['is_bulk_recharge'] ?? false) !== true) {
                    return ['skipped' => true, 'reason' => 'order is not flagged is_bulk_recharge'];
                }

                if ($order->payment_status !== 'paid') {
                    return ['skipped' => true, 'reason' => 'order is not paid'];
                }

                if (isset($order->metadata['corporate_recharge_results'])) {
                    return ['skipped' => true, 'reason' => 'already processed'];
                }

                return $this->processBulkRecharge($order, $app);
            },
            company: $entity->company,
        );
    }

    private function processBulkRecharge(Order $order, AppInterface $app): array
    {
        $company = $order->company;
        $wallet = $company->createAppWallet($app, ['name' => 'default']);

        $creditMeta = (new BuildWalletTransactionMetaAction(
            source: TransactionSourceEnum::RECHARGE_MANUAL,
            actorUserId: $order->users_id,
            externalReference: $order->uuid,
            reason: 'Corporate bulk recharge funding',
            additional: [
                'service' => OrderTypeEnum::PASO_RAPIDO->value,
                'order_id' => $order->getId(),
                'is_bulk_recharge' => true,
            ],
        ))->execute();

        $wallet->depositFloat((float) $order->total_gross_amount, $creditMeta);

        $dni = (string) ($company->get('rnc') ?? '');
        $bankTransaction = $this->resolveBankTransaction($order);

        $service = null;
        $serviceError = null;

        try {
            $service = new PasoRapidoService($app, $company);
        } catch (Throwable $e) {
            report($e);
            $serviceError = $e->getMessage();
        }

        $results = [];

        foreach ($order->items as $item) {
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
                $debitMeta = (new BuildWalletTransactionMetaAction(
                    source: TransactionSourceEnum::PAYMENT,
                    actorUserId: $order->users_id,
                    externalReference: "bulk:{$order->uuid}:{$tagNumber}",
                    reason: "Corporate bulk recharge TAG {$tagNumber}",
                    additional: [
                        'service' => OrderTypeEnum::PASO_RAPIDO->value,
                        'tag_number' => $tagNumber,
                        'order_id' => $order->getId(),
                        'order_item_id' => $item->getId(),
                    ],
                ))->execute();

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

                $results[$tagNumber] = [
                    'status' => 'success',
                    'amount' => $amount,
                    'order_item_id' => $item->getId(),
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
                            'order_id' => $order->getId(),
                            'order_item_id' => $item->getId(),
                        ]);
                    } catch (Throwable $reversalEx) {
                        report($reversalEx);
                        Log::error('BulkRechargeTagsActivity: reversal failed — manual intervention required', [
                            'tag' => $tagNumber,
                            'amount' => $amount,
                            'order_id' => $order->getId(),
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

        $metadata = $order->metadata ?? [];
        $metadata['corporate_recharge_results'] = $results;
        $order->metadata = $metadata;
        $order->saveOrFail();

        $successCount = count(array_filter($results, fn ($r) => $r['status'] === 'success'));
        $failCount = count($results) - $successCount;

        return [
            'result' => true,
            'order_id' => $order->getId(),
            'total' => count($results),
            'success' => $successCount,
            'failed' => $failCount,
        ];
    }

    private function resolveBankTransaction(Order $order): string
    {
        $intentId = $order->get(EchoPayCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value);

        if ($intentId) {
            return explode(':', (string) $intentId)[1] ?? (string) $intentId;
        }

        return 'wallet_bulk_' . $order->uuid;
    }
}
