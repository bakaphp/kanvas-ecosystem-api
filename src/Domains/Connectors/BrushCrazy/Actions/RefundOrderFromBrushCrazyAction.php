<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kanvas\Connectors\BrushCrazy\Support\RefundLedger;
use Kanvas\Connectors\BrushCrazy\Support\SiteBooking;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;
use Throwable;

/**
 * Returns to the card the money staff refunded in BrushCrazy on a site order — one ledger entry,
 * through the payment's own processor (ProcessorFactory → PaymentProcessorInterface::refund(), the
 * same entry point as the `refundPayment` mutation), so any processor Souk registers works here.
 *
 * Runs outside the mirror's transaction: the entry is claimed under a short row lock, the processor
 * is called with no lock held, and the outcome is written under a second one. It never refunds more
 * than Kanvas captured minus what Kanvas already refunded. A processor that is not registered, or
 * that refuses refunds (PayWay), leaves the entry `manual` for someone to refund by hand.
 */
class RefundOrderFromBrushCrazyAction
{
    public function __construct(
        protected Order $order,
        protected string $key,
    ) {
    }

    /**
     * @return array<string, mixed>|null the settled entry, or null when this entry is not pending
     */
    public function execute(): ?array
    {
        $entry = $this->claim();

        if ($entry === null) {
            return null;
        }

        try {
            $outcome = $this->refund((int) ($entry['cents'] ?? 0));
        } catch (Throwable $e) {
            report($e);
            $outcome = ['status' => RefundLedger::FAILED, 'message' => $e->getMessage()];
        }

        $settled = RefundLedger::update($this->order, function (array $ledger) use ($outcome) {
            $ledger[$this->key] = array_merge($ledger[$this->key] ?? [], $outcome, ['settled_at' => Carbon::now()->toIso8601String()]);

            return $ledger;
        })[$this->key];

        if (in_array($settled['status'], [RefundLedger::FAILED, RefundLedger::MANUAL], true)) {
            Log::warning('BrushCrazy refund of a Kanvas order needs attention', [
                'order_id' => $this->order->getId(),
                'companies_id' => $this->order->companies_id,
                'refund' => $this->key,
                'entry' => $settled,
            ]);
        }

        return $settled;
    }

    /**
     * A retried job finds its own entry `processing`; once that is stale the earlier attempt died
     * between the processor call and the write, and only a person can tell whether money moved.
     */
    protected function claim(): ?array
    {
        $claimed = null;

        RefundLedger::update($this->order, function (array $ledger) use (&$claimed) {
            $entry = $ledger[$this->key] ?? null;
            $now = Carbon::now();

            if (($entry['status'] ?? null) === RefundLedger::PROCESSING
                && Carbon::parse($entry['started_at'] ?? $now)->lt($now->copy()->subMinutes(RefundLedger::STALE_PROCESSING_MINUTES))
            ) {
                $ledger[$this->key]['status'] = RefundLedger::MANUAL;
                $ledger[$this->key]['message'] = 'The refund was interrupted after it reached the processor; check the processor before refunding again.';
            }

            if (($entry['status'] ?? null) !== RefundLedger::PENDING) {
                return $ledger;
            }

            $ledger[$this->key]['status'] = RefundLedger::PROCESSING;
            $ledger[$this->key]['started_at'] = $now->toIso8601String();
            $claimed = $ledger[$this->key];

            return $ledger;
        });

        return $claimed;
    }

    /**
     * @return array<string, mixed> the fields to merge into the entry
     */
    protected function refund(int $cents): array
    {
        $payments = $this->order->payments()
            ->reorder('id')
            ->whereIn('status', [PaymentStatusEnum::PAID->value, PaymentStatusEnum::REVERSED->value])
            ->get();

        if ($payments->isEmpty()) {
            return ['status' => RefundLedger::MANUAL, 'amount_cents' => 0, 'message' => 'The order has no captured Kanvas payment to refund.'];
        }

        $amount = min($cents, $this->refundableCents($payments->all()));

        if ($amount <= 0) {
            return [
                'status' => RefundLedger::SKIPPED,
                'amount_cents' => 0,
                'message' => 'Kanvas had already refunded everything it captured on this order.',
            ];
        }

        $left = $amount;
        $refunded = 0;
        $outcome = ['status' => RefundLedger::REFUNDED];
        $references = [];
        $refundIds = [];
        $processors = [];

        foreach ($payments as $payment) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $this->paymentRefundableCents($payment));

            if ($take <= 0 || $payment->status !== PaymentStatusEnum::PAID->value) {
                continue;
            }

            $attempt = $this->refundPayment($payment, $take);
            $processors[] = $attempt['processor'];

            if ($attempt['status'] !== RefundLedger::REFUNDED) {
                $outcome = ['status' => $attempt['status'], 'message' => $attempt['message']];

                break;
            }

            $refunded += $take;
            $left -= $take;
            $references[] = $attempt['reference'];

            if ($attempt['payment_refund_id'] !== null) {
                $refundIds[] = $attempt['payment_refund_id'];
            }
        }

        if ($outcome['status'] === RefundLedger::REFUNDED && $left > 0) {
            $outcome = [
                'status' => RefundLedger::MANUAL,
                'message' => 'Kanvas could only refund ' . $refunded . ' of ' . $amount . ' cents through the order payments.',
            ];
        }

        if ($outcome['status'] === RefundLedger::REFUNDED && $amount < $cents) {
            $outcome['message'] = 'Capped at what Kanvas still held: ' . $amount . ' of ' . $cents . ' cents.';
        }

        return $outcome + [
            'amount_cents' => $refunded,
            'processors' => array_values(array_unique(array_filter($processors))),
            'processor_references' => array_values(array_filter($references)),
            'payment_refund_ids' => $refundIds,
        ];
    }

    /**
     * @return array{status: string, message: string|null, processor: string|null, reference: string|null, payment_refund_id: int|null}
     */
    protected function refundPayment(Payments $payment, int $cents): array
    {
        $processorName = (string) ($payment->paymentMethod?->processor ?? $payment->processor ?? '');
        $result = ['status' => RefundLedger::MANUAL, 'message' => null, 'processor' => $processorName ?: null, 'reference' => null, 'payment_refund_id' => null];

        if ($processorName === '') {
            return ['message' => "Payment #{$payment->getId()} has no processor; refund it by hand."] + $result;
        }

        try {
            $processor = ProcessorFactory::make($processorName, $this->order->app, $payment->company);
            // Stripe keeps the reason on its PaymentRefund: that is how the push activity knows
            // this refund came from BrushCrazy and must not be sent back.
            $response = $processor->refund(
                $payment,
                $this->order,
                round($cents / 100, 2),
                [
                    'reason' => RefundLedger::REASON_PREFIX . $this->key,
                    'idempotency_key' => 'brushcrazy-refund:' . $this->order->uuid . ':' . $this->key . ':' . $payment->getId(),
                ]
            );
        } catch (InvalidArgumentException|DomainException $e) {
            return ['message' => "{$processorName} cannot refund through Kanvas ({$e->getMessage()}); refund it by hand."] + $result;
        }

        if (! $response->success) {
            return ['status' => RefundLedger::FAILED, 'message' => $response->message] + $result;
        }

        $reference = $response->transactionId !== '' ? $response->transactionId : null;
        $paymentRefundId = PaymentRefund::query()
            ->where('payments_id', $payment->getId())
            ->when($reference !== null, fn ($query) => $query->where('processor_refund_id', $reference))
            ->orderByDesc('id')
            ->value('id');

        return [
            'status' => RefundLedger::REFUNDED,
            'message' => null,
            'processor' => $processorName,
            'reference' => $reference,
            'payment_refund_id' => $paymentRefundId !== null ? (int) $paymentRefundId : null,
        ];
    }

    /**
     * Captured minus refunded. A payment some processor flipped to REVERSED without a refund row
     * (Azul, CardNet) is counted as fully refunded.
     *
     * @param  array<int, Payments>  $payments
     */
    protected function refundableCents(array $payments): int
    {
        $captured = 0;
        $refunded = 0;

        foreach ($payments as $payment) {
            $amount = SiteBooking::cents($payment->amount);
            $paymentRefunded = SiteBooking::cents($payment->getRefundedAmount());

            $captured += $amount;
            $refunded += $payment->status === PaymentStatusEnum::REVERSED->value && $paymentRefunded === 0 ? $amount : $paymentRefunded;
        }

        $refunded += RefundLedger::refundedWithoutRecordCents(RefundLedger::of($this->order), $this->key);

        return max(0, $captured - $refunded);
    }

    protected function paymentRefundableCents(Payments $payment): int
    {
        return max(0, SiteBooking::cents($payment->amount) - SiteBooking::cents($payment->getRefundedAmount()));
    }
}
