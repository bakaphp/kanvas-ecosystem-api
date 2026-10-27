<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Taxes\DataTransferObject\TaxReversal;
use Kanvas\Souk\Taxes\Enums\TaxReversalStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Exceptions\IllegalTaxTransition;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxOperation;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;
use Throwable;

class ReverseTaxTransactionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 60, 300, 900, 1800];

    public function __construct(
        public readonly OrderTaxSnapshot $snapshot,
        public readonly PaymentRefund $refund,
        public readonly float $amount,
        public readonly bool $isFullReversal,
        public readonly Apps $app,
    ) {
    }

    public function uniqueId(): string
    {
        return 'reverse-tax-' . $this->snapshot->uuid . '-' . $this->refund->uuid;
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $lock = Cache::lock('tax-snapshot:' . $this->snapshot->uuid, 120);
        $lock->block(10);

        $snapshot = OrderTaxSnapshot::query()->find($this->snapshot->id);

        try {
            if (in_array($snapshot->status, [
                TaxStatusEnum::REVERSED->value,
                TaxStatusEnum::VOIDED->value,
                TaxStatusEnum::SUPERSEDED->value,
            ])) {
                return;
            }

            if ($this->isFullReversal && $snapshot->status === TaxStatusEnum::CALCULATED->value) {
                $provider = new TaxProviderResolver($this->app)->resolveFromSnapshot($snapshot);
                $provider->void($snapshot);
                $snapshot->transition(TaxStatusEnum::CALCULATED->value, TaxStatusEnum::VOIDED->value);

                return;
            }

            $reversibleStatuses = [
                TaxStatusEnum::COMMITTED->value,
                TaxStatusEnum::PARTIALLY_REVERSED->value,
            ];

            if (! in_array($snapshot->status, $reversibleStatuses)) {
                \Sentry\captureMessage(
                    "Tax reversal skipped for snapshot {$snapshot->uuid}: unexpected status '{$snapshot->status}'"
                );

                return;
            }

            $idempotencyKey = hash('sha256', 'reverse:' . $snapshot->uuid . ':' . $this->refund->uuid);

            $alreadyReversed = TaxOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', TaxReversalStatusEnum::REVERSED->value)
                ->exists();

            if ($alreadyReversed) {
                return;
            }

            $operation = TaxOperation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($operation === null) {
                $operation = new TaxOperation();
                $operation->snapshot_id = $snapshot->id;
                $operation->kind = 'reverse';
                $operation->idempotency_key = $idempotencyKey;
                $operation->status = TaxReversalStatusEnum::PENDING->value;
                $operation->provider = $snapshot->provider;
                $operation->provider_response = ['refund_uuid' => $this->refund->uuid];
                $operation->saveOrFail();
            }

            $operation->status = TaxReversalStatusEnum::REVERSING->value;
            $operation->saveOrFail();

            $provider = new TaxProviderResolver($this->app)->resolveFromSnapshot($snapshot);

            $reversalDto = new TaxReversal(
                snapshotUuid: $snapshot->uuid,
                amount: (string) $this->amount,
                taxAmount: (string) $this->computeProportionalTax($snapshot, $this->amount),
                refundUuid: $this->refund->uuid,
            );

            $provider->reverse($snapshot, $reversalDto);

            DB::connection('commerce')->table('order_tax_snapshots')
                ->where('id', $snapshot->id)
                ->where('remaining_reversible_amount', '>=', $this->amount)
                ->decrement('remaining_reversible_amount', $this->amount);

            $operation->status = TaxReversalStatusEnum::REVERSED->value;
            $operation->saveOrFail();

            $snapshot->refresh();

            $newStatus = $this->isFullReversal
                ? TaxStatusEnum::REVERSED->value
                : TaxStatusEnum::PARTIALLY_REVERSED->value;

            $snapshot->update(['status' => $newStatus]);
        } catch (IllegalTaxTransition $e) {
            \Sentry\captureMessage(
                "Tax reversal uncertain outcome for snapshot {$snapshot->uuid}: " . $e->getMessage()
            );
            $snapshot->status = TaxStatusEnum::NEEDS_RECONCILE->value;
            $snapshot->saveOrFail();
        } catch (Throwable $e) {
            if (isset($operation)) {
                $operation->status = TaxReversalStatusEnum::REVERSAL_FAILED->value;
                $operation->saveOrFail();
            }

            $snapshot->status = TaxStatusEnum::REVERSAL_FAILED->value;
            $snapshot->saveOrFail();

            \Sentry\captureMessage(
                "Tax reversal failed for snapshot {$snapshot->uuid}: " . $e->getMessage()
            );

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function queue(): string
    {
        return 'souk-tax';
    }

    private function computeProportionalTax(OrderTaxSnapshot $snapshot, float $reversalAmount): float
    {
        $taxableAmount = (float) $snapshot->taxable_amount;

        if ($taxableAmount <= 0) {
            return 0.0;
        }

        $taxRatio = (float) $snapshot->tax_amount / $taxableAmount;

        return round($reversalAmount * $taxRatio, 4);
    }
}
