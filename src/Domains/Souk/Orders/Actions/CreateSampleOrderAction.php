<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderDto;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemDto;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

/**
 * Creates a $0 sample / giveaway sales order in Souk (Kanvas-first). It lands as a DRAFT and touches
 * no ERP — approval is what fires the workflow that pushes it out (PushSalesOrderToAcumaticaActivity),
 * so nothing reaches Acumatica until a human signs off.
 */
class CreateSampleOrderAction
{
    /**
     * @param array<int, array{variant: Variants, quantity: int|float, name?: string|null}> $lines
     */
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected Regions $region,
        protected People $people,
        protected Currencies $currency,
        protected array $lines,
        protected ?string $note = null,
    ) {
    }

    public function execute(): Order
    {
        $token = Str::uuid()->toString();

        $createOrder = new CreateOrderAction(
            new OrderDto(
                app: $this->app,
                region: $this->region,
                company: $this->company,
                people: $this->people,
                user: $this->user,
                token: $token,
                orderNumber: $this->nextOrderNumber(),
                shippingAddress: null,
                billingAddress: null,
                total: 0.0,
                taxes: 0.0,
                totalDiscount: 0.0,
                totalShipping: 0.0,
                status: OrderStatusEnum::DRAFT->value,
                checkoutToken: $token,
                currency: $this->currency,
                items: new DataCollection(OrderItemDto::class, $this->buildItems()),
                customerNote: $this->note,
                reference: 'sample-order',
            ),
        );
        $createOrder->runWorkflow = false;

        return $createOrder->execute();
    }

    /**
     * @return array<int, OrderItemDto>
     */
    private function buildItems(): array
    {
        $items = [];

        foreach ($this->lines as $line) {
            $variant = $line['variant'];
            $items[] = new OrderItemDto(
                app: $this->app,
                variant: $variant,
                name: $line['name'] ?? $variant->name,
                sku: (string) $variant->sku,
                quantity: (float) $line['quantity'],
                price: 0.0,
                tax: 0.0,
                discount: 0.0,
                currency: $this->currency,
            );
        }

        return $items;
    }

    private function nextOrderNumber(): int
    {
        return (int) Order::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->max('order_number') + 1;
    }
}
