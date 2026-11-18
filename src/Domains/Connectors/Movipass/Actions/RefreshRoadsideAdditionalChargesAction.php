<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideAdditionalChargeStatusEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

/**
 * The charge's truth lives on its child order and that order's payments; the copy on the case is
 * what the app and the technician read. That copy sits in client-writable metadata, so every
 * charge is re-derived on every run — never only the ones the copy claims are still open. A failed
 * card fires no order event, so this runs on the parent's events as well as the child's.
 */
class RefreshRoadsideAdditionalChargesAction
{
    use InteractsWithAssistanceCase;

    public const string APPROVED_EVENT = 'additional_payment_approved';

    public function __construct(
        private readonly Order $order,
    ) {
    }

    /**
     * @return array<int, array> the charges approved by this refresh
     */
    public function execute(): array
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $charges = is_array($assistanceCase['additional_charges'] ?? null) ? $assistanceCase['additional_charges'] : [];

        if ($charges === []) {
            return [];
        }

        $caseClosed = $this->isTerminalAssistanceStatus($this->order->orderStatus?->slug);
        $approved = [];
        $changed = false;

        foreach ($charges as $index => $charge) {
            $current = RoadsideAdditionalChargeStatusEnum::tryFrom((string) ($charge['status'] ?? ''));

            $chargeOrder = Order::query()
                ->whereKey((int) ($charge['order_id'] ?? 0))
                ->where('parent_id', $this->order->getId())
                ->first();

            $resolved = $this->resolveStatus($chargeOrder, $caseClosed);

            if ($resolved === $current) {
                continue;
            }

            if ($resolved === RoadsideAdditionalChargeStatusEnum::CANCELLED) {
                $chargeOrder?->fulfillCancelled();
            }

            $charges[$index]['status'] = $resolved->value;
            $charges[$index]['status_updated_at'] = Carbon::now()->toISOString();
            $changed = true;

            if ($resolved === RoadsideAdditionalChargeStatusEnum::APPROVED) {
                $approved[] = $charges[$index];
            }
        }

        if (! $changed) {
            return [];
        }

        $assistanceCase['additional_charges'] = $charges;
        $assistanceCase['additional_payment_pending'] = $this->hasOutstandingAdditionalCharges($assistanceCase);
        $this->saveAssistanceCase($this->order, $assistanceCase);

        $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);
        RefreshActiveAssistanceEvent::dispatch($this->order, $mechanicId > 0 ? $mechanicId : (int) $this->order->users_id);

        if ($approved !== []) {
            $notification = new RoadsideAssistanceStatusNotification(
                $this->order,
                'Additional payment approved',
                'The additional charge was paid. The technician can continue the service.',
                self::APPROVED_EVENT,
            );
            $this->notifySafely($this->resolveCaseUser($mechanicId), $notification);
            $this->notifySafely($this->resolveCaseUser($this->order->users_id), $notification);
        }

        return $approved;
    }

    private function resolveStatus(?Order $chargeOrder, bool $caseClosed): RoadsideAdditionalChargeStatusEnum
    {
        if ($chargeOrder?->payment_status === PaymentStatusEnum::PAID->value) {
            return RoadsideAdditionalChargeStatusEnum::APPROVED;
        }

        // Paid wins over closed: money that already moved has to show as collected, and a charge
        // nobody can pay any more must stop blocking anything.
        if ($caseClosed || $chargeOrder === null) {
            return RoadsideAdditionalChargeStatusEnum::CANCELLED;
        }

        $latestPayment = Payments::getLatestForEntity(
            $chargeOrder,
            array_column(PaymentStatusEnum::cases(), 'value'),
        );

        return $latestPayment?->status === PaymentStatusEnum::FAILED->value
            ? RoadsideAdditionalChargeStatusEnum::REJECTED
            : RoadsideAdditionalChargeStatusEnum::PENDING;
    }
}
