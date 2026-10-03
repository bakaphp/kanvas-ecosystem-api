<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

/**
 * Order access for a customer-facing tool. The shopper is whoever the session is keyed on — never a
 * model-supplied name or email — so one shopper can never be steered into another shopper's orders.
 * Anonymous sessions have no People and get nothing from here; the tool decides what to ask for.
 */
trait ResolvesShopperOrdersForTool
{
    use HasKanvasContext;

    protected ?Session $session = null;

    protected function shopper(): ?People
    {
        return $this->session?->people();
    }

    protected function ordersQuery(): Builder
    {
        return Order::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted();
    }

    protected function shopperOrdersQuery(People $shopper): Builder
    {
        return $this->ordersQuery()->where('people_id', $shopper->getId());
    }

    /**
     * What a shopper may see about their own order: no customer contact echo, no affiliate or
     * commission data, no internal ids.
     *
     * @return array<string, mixed>
     */
    protected function presentOrderToShopper(Order $order, bool $withItems = false): array
    {
        $summary = [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'order_date' => $order->created_at?->toDateString(),
            'shipped_date' => $order->shipped_date,
            'currency' => $order->currency,
            'total' => (float) $order->total_gross_amount,
        ];

        if ($withItems) {
            $summary['items'] = $order->items()->get()->map(static fn (OrderItem $item): array => [
                'product' => $item->product_name,
                'variant' => $item->variant_name,
                'sku' => $item->product_sku,
                'quantity' => (float) $item->quantity,
                'quantity_fulfilled' => (float) $item->quantity_fulfilled,
                'unit_price' => (float) $item->unit_price_gross_amount,
            ])->all();
        }

        return $summary;
    }
}
