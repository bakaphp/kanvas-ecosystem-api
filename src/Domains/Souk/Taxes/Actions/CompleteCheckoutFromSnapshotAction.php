<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use RuntimeException;
use Throwable;

class CompleteCheckoutFromSnapshotAction
{
    public function __construct(
        private readonly OrderTaxSnapshot $snapshot,
        private readonly string $paymentIntentId,
        private readonly Apps $app,
    ) {
    }

    public function execute(): Order
    {
        $result = Cache::lock('checkout:' . $this->snapshot->uuid, 120)->get(function (): Order {
            $snapshot = OrderTaxSnapshot::getById($this->snapshot->id, $this->app);

            if ($snapshot->order_id !== null) {
                return Order::getById($snapshot->order_id, $this->app);
            }

            return $this->createOrderFromPayload($snapshot);
        });

        if ($result === null) {
            throw new ValidationException('Checkout is already being processed for this snapshot');
        }

        return $result;
    }

    private function createOrderFromPayload(OrderTaxSnapshot $snapshot): Order
    {
        $payload = $snapshot->checkout_payload ?? [];

        try {
            $order = DB::connection('commerce')->transaction(function () use ($snapshot, $payload): Order {
                $order = $this->buildOrder($snapshot, $payload);

                $actualHash = self::linesHash($payload['lines'] ?? []);

                if ($actualHash !== ($payload['lines_hash'] ?? $snapshot->input_hash)) {
                    $order->holdForTaxMismatch();
                }

                $snapshot->order_id = $order->getId();
                $snapshot->payment_intent_id = $this->paymentIntentId;
                $snapshot->saveOrFail();

                return $order;
            });
        } catch (Throwable $e) {
            CreditReservation::settleHeldFromPayload($payload, 'released');

            throw $e;
        }

        CreditReservation::settleHeldFromPayload($payload, 'consumed');

        return $order;
    }

    private function buildOrder(OrderTaxSnapshot $snapshot, array $payload): Order
    {
        $totals = $payload['totals'] ?? [];
        $context = $payload['order_context'] ?? [];

        $chargedOrder = $this->findOrderByPaymentIntent();

        if ($chargedOrder !== null) {
            return $chargedOrder;
        }

        $order = Order::fromApp($this->app)
            ->where('checkout_token', $snapshot->cart_session_id ?? '')
            ->first();

        $isNew = $order === null;

        if ($isNew) {
            $order = new Order();
            $order->apps_id = $snapshot->apps_id;
            $order->companies_id = (int) ($context['companies_id']
                ?? throw new RuntimeException('Cannot resolve companies_id from snapshot payload'));
            if (isset($context['region_id'])) {
                $order->region_id = (int) $context['region_id'];
            }
            if (isset($context['people_id'])) {
                $order->people_id = (int) $context['people_id'];
            }
            if (isset($context['users_id'])) {
                $order->users_id = (int) $context['users_id'];
            }
            if (isset($payload['currency'])) {
                $order->currency = (string) $payload['currency'];
            }
            $order->checkout_token = $snapshot->cart_session_id ?? '';
        }

        $order->tax_amount = $snapshot->tax_amount;
        $order->tax_source = 'souk';
        $order->subtotal_amount = (float) ($totals['subtotal'] ?? 0.0);
        $order->discount_amount = (float) ($totals['discount'] ?? 0.0);
        $order->total_payable_amount = (float) ($totals['payable'] ?? 0.0);
        $order->wallet_tender = (float) ($totals['wallet_tender'] ?? 0.0);
        $order->total_net_amount = (float) ($totals['gateway_due'] ?? 0.0);
        $order->payment_intent_id = $this->paymentIntentId;

        try {
            $order->saveOrFail();
        } catch (QueryException $e) {
            // Unique payment_intent_id: a concurrent completion already inserted this order.
            $existing = $e->getCode() === '23000' ? $this->findOrderByPaymentIntent() : null;

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        if ($isNew) {
            $this->createItemsFromPayload($order, $payload['lines'] ?? []);
        }

        return $order;
    }

    private function findOrderByPaymentIntent(): ?Order
    {
        return Order::fromApp($this->app)
            ->where('payment_intent_id', $this->paymentIntentId)
            ->first();
    }

    private function createItemsFromPayload(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            if (! isset($line['variant_id'])) {
                continue;
            }

            $item = new OrderItem();
            $item->apps_id = $order->apps_id;
            $item->order_id = $order->getId();
            $item->variant_id = (int) $line['variant_id'];
            $item->product_name = (string) ($line['product_name'] ?? '');
            $item->product_sku = (string) ($line['product_sku'] ?? '');
            $item->variant_name = (string) ($line['variant_name'] ?? $line['product_name'] ?? '');
            $item->quantity = (int) ($line['quantity'] ?? 1);
            $item->unit_price_net_amount = (float) ($line['unit_price_net_amount'] ?? 0.0);
            $item->unit_price_gross_amount = (float) ($line['unit_price_net_amount'] ?? 0.0);
            $item->currency = $order->currency;
            $item->saveOrFail();
        }
    }

    public static function linesHash(array $lines): string
    {
        $normalized = array_map(fn (array $line): array => [
            'variant_id' => $line['variant_id'],
            'quantity' => $line['quantity'],
            'unit_price_net_amount' => $line['unit_price_net_amount'],
        ], $lines);

        usort($normalized, fn ($a, $b): int => $a['variant_id'] <=> $b['variant_id']);

        return hash('sha256', json_encode($normalized));
    }
}
