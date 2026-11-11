<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Payments\Actions\SyncPayablePaymentStatusAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxReversalStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Jobs\ReverseTaxTransactionJob;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxOperation;
use Kanvas\Users\Models\Users;

/**
 * @property int $apps_id
 * @property int $companies_id
 * @property int $payments_id
 * @property int $users_id
 * @property float $amount
 * @property string|null $currency
 * @property string|null $reason
 * @property string|null $note
 * @property string|null $processor_refund_id
 * @property string|null $idempotency_key
 * @property string $status
 * @property array|null $metadata
 */
class PaymentRefund extends BaseModel
{
    use UuidTrait;

    protected $table = 'payment_refunds';
    protected $guarded = [];

    protected $casts = [
        'metadata' => Json::class,
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payments::class, 'payments_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function attempt(): int
    {
        return (int) ($this->metadata['attempt'] ?? 1);
    }

    public function isCompleted(): bool
    {
        return $this->status === RefundStatusEnum::COMPLETED->value;
    }

    public function markAsCompleted(array $metadata = []): void
    {
        $this->status = RefundStatusEnum::COMPLETED->value;

        if (! empty($metadata)) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }

        $this->save();

        $payment = $this->payment;

        if ($payment->isFullyRefunded()) {
            $payment->status = PaymentStatusEnum::REVERSED->value;
            $payment->saveQuietly();

            if ($payment->payable && method_exists($payment->payable, 'markAsReversed')) {
                $payment->payable->markAsReversed($payment->user);
            } elseif ($payment->payable) {
                new SyncPayablePaymentStatusAction($payment->payable)->execute();
            }
        } elseif ($payment->payable) {
            new SyncPayablePaymentStatusAction($payment->payable)->execute();
        }

        $payment->addLog('refund_completed', [
            'refund_id' => $this->id,
            'amount' => $this->amount,
            'fully_refunded' => $payment->isFullyRefunded(),
        ], actor: $this->user);

        $this->dispatchTaxReversal($payment);
    }

    private function dispatchTaxReversal(Payments $payment): void
    {
        $payable = $payment->payable;

        if ($payable === null) {
            return;
        }

        $snapshot = OrderTaxSnapshot::query()
            ->where('order_id', $payable->id)
            ->whereNotIn('status', [TaxStatusEnum::VOIDED->value, TaxStatusEnum::SUPERSEDED->value])
            ->first();

        if ($snapshot === null) {
            return;
        }

        $isFullReversal = (float) $this->amount >= (float) $snapshot->remaining_reversible_amount;

        $idempotencyKey = TaxOperation::reversalIdempotencyKey($snapshot, $this->uuid);

        $existing = TaxOperation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->whereNotIn('status', [TaxReversalStatusEnum::REVERSED->value, TaxReversalStatusEnum::REVERSAL_FAILED->value])
            ->first();

        if ($existing === null) {
            $operation = new TaxOperation();
            $operation->snapshot_id = $snapshot->id;
            $operation->kind = 'reverse';
            $operation->idempotency_key = $idempotencyKey;
            $operation->status = TaxReversalStatusEnum::PENDING->value;
            $operation->provider_response = ['refund_uuid' => $this->uuid];
            $operation->saveOrFail();
        }

        ReverseTaxTransactionJob::dispatch(
            $snapshot,
            $this,
            (float) $this->amount,
            $isFullReversal,
            $this->app,
        );
    }

    public function markAsFailed(array $metadata = []): void
    {
        $this->status = RefundStatusEnum::FAILED->value;

        if (! empty($metadata)) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }

        $this->save();
    }
}
