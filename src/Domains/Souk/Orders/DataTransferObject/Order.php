<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions as ModelsRegions;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class Order extends Data
{
    #[DataCollectionOf(OrderItem::class)]
    public DataCollection|array $items;

    public function __construct(
        public readonly Apps $app,
        public readonly Regions|ModelsRegions $region,
        public readonly CompanyInterface $company,
        public readonly People $people,
        public readonly UserInterface $user,
        public readonly string $token,
        public readonly string $orderNumber,
        public readonly ?Address $shippingAddress,
        public readonly ?Address $billingAddress,
        public float $total,
        public float $taxes,
        public float $totalDiscount,
        public float $totalShipping,
        public readonly string $status, //enums
        public readonly string $checkoutToken,
        public readonly Currencies $currency,
        DataCollection|array $items,
        public readonly ?string $email = null,
        public readonly mixed $metadata = null,
        public readonly float $weight = 0.0,
        public readonly ?string $shippingMethod = null,
        public readonly ?string $phone = null,
        public readonly ?string $customerNote = null,
        public readonly ?string $fulfillmentStatus = null,
        public readonly ?string $shippingDate = null,
        public readonly ?string $shippedDate = null,
        public readonly ?string $languageCode = null,
        public readonly array $paymentGatewayName = [],
    ) {
        $this->items = is_array($items) ? $this->getOrderItems($items) : $items;
    }

    public function fulfill(): bool
    {
        return $this->fulfillmentStatus === 'fulfilled';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getOrderItems(array $lineItems): DataCollection
    {
        $orderItems = [];

        if (! isset($lineItems[0]['name']) || ! isset($lineItems[0]['id']) || ! isset($lineItems[0]['quantity'])) {
            throw new InvalidArgumentException('Not the correct item structure to generate a line item');
        }

        foreach ($lineItems as $lineItem) {
            $variant = Variants::getById($lineItem['id'], $this->app);

            //this shouldn't happen but just in case
            if (! $variant) {
                continue;
            }

            $item = new OrderItem(
                app: $this->app,
                variant: $variant,
                name: $lineItem['name'],
                sku: (string) ($variant->sku ?? $lineItem['id']),
                quantity: $lineItem['quantity'],
                price: (float) $lineItem['price'],
                tax: $lineItem['tax'] ?? 0,
                discount: (float) ($lineItem['total_discount'] ?? 0),
                currency: Currencies::getByCode('USD'),
                quantityShipped: 0
            );

            $orderItems[] = $item;

            $this->total += $item->getTotal();
            $this->totalDiscount += $item->getTotalDiscount();
            $this->taxes += $item->getTotalTax();
        }

        return OrderItem::collect($orderItems, DataCollection::class);
    }
}
