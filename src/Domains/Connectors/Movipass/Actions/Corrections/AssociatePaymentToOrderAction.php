<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Payments\Actions\SyncPayablePaymentStatusAction;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;

class AssociatePaymentToOrderAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected readonly Payments $payment,
        protected readonly string $reason,
        protected readonly array $evidenceUrls = [],
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $previousPayableId = (int) $this->payment->payable_id;
            $previousPayableType = (string) $this->payment->payable_type;

            $this->payment->payable_id = $this->order->id;
            $this->payment->payable_type = Order::class;
            $this->payment->saveQuietly();

            if ($previousPayableId && $previousPayableType === Order::class && $previousPayableId !== $this->order->id) {
                $sourceOrder = Order::find($previousPayableId);

                if ($sourceOrder) {
                    $this->revertSourceOrder($sourceOrder);

                    activity()
                        ->causedBy($this->user)
                        ->performedOn($sourceOrder)
                        ->withProperties([
                            'changes' => [
                                'payment_uuid' => ['old' => $this->payment->uuid, 'new' => null],
                                'transferred_to_order_id' => ['old' => null, 'new' => $this->order->id],
                            ],
                            'reason' => $this->reason,
                            'evidence' => $this->evidenceUrls,
                            'order_id' => $sourceOrder->id,
                            'order_number' => $sourceOrder->order_number,
                        ])
                        ->log('payment-transferred-out');
                }
            }

            new SyncPayablePaymentStatusAction($this->order)->execute();

            if ($this->order->isPaid()) {
                $this->order->checkPayments();
            }

            $this->logCorrection(
                'associate-payment',
                [
                    'payment_uuid' => ['old' => null, 'new' => $this->payment->uuid],
                    'payment_amount' => ['old' => null, 'new' => $this->payment->amount],
                    'payment_status' => ['old' => null, 'new' => $this->payment->status],
                    'transferred_from_order_id' => ['old' => $previousPayableId ?: null, 'new' => $this->order->id],
                ],
                $this->reason,
                $this->evidenceUrls,
            );
        });
    }

    private function revertSourceOrder(Order $sourceOrder): void
    {
        if ($sourceOrder->payments()->doesntExist()) {
            $sourceOrder->updateQuietly(['payment_status' => 'unpaid']);
        } else {
            new SyncPayablePaymentStatusAction($sourceOrder)->execute();
        }

        if ($sourceOrder->orderStatus?->slug === 'paid') {
            $this->rollbackOrderStatusFromPaid($sourceOrder);
        }
    }

    private function rollbackOrderStatusFromPaid(Order $sourceOrder): void
    {
        $currentHistory = OrderTransitionHistory::where('order_id', $sourceOrder->id)
            ->where('is_current', true)
            ->first();

        if (! $currentHistory?->from_status_id) {
            return;
        }

        $previousStatus = OrderStatus::find($currentHistory->from_status_id);

        if (! $previousStatus) {
            return;
        }

        $paidStatus = $sourceOrder->orderStatus;

        $currentHistory->updateQuietly([
            'is_current' => false,
            'ended_at' => now(),
            'ended_by' => $this->user->getId(),
        ]);

        $sourceOrder->updateQuietly(['order_status_id' => $previousStatus->id]);

        OrderTransitionHistory::create([
            'apps_id' => $sourceOrder->apps_id,
            'companies_id' => $sourceOrder->companies_id,
            'order_id' => $sourceOrder->id,
            'transition_id' => null,
            'from_status_id' => $paidStatus->id,
            'to_status_id' => $previousStatus->id,
            'description' => 'Order status reverted from ' . $paidStatus->slug . ' to ' . $previousStatus->slug . ' due to payment transfer',
            'metadata' => is_array($sourceOrder->metadata) ? $sourceOrder->metadata : [],
            'is_current' => true,
            'changed_at' => now(),
            'changed_by' => $this->user->getId(),
        ]);
    }
}
