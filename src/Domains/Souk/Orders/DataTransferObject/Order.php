<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class Order extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly Regions $region,
        public readonly CompanyInterface $company,
        public readonly People $people,
        public readonly UserInterface $user,
        public readonly string $email,
        public readonly string $token,
        public readonly string $orderNumber,
        public readonly Address $shippingAddress,
        public readonly Address $billingAddress,
        public readonly float $total,
        public readonly float $taxes,
        public readonly float $totalDiscount,
        public readonly float $totalShipping,
        public readonly string $status, //enums
        public readonly string $shippingMethod,
        public readonly string $checkoutToken,
        public readonly Currencies $currency,
        #[DataCollectionOf(OrderItem::class)]
        public readonly DataCollection $items,
        public readonly ?string $metadata = null,
        public readonly float $weight = 0.0,
        public readonly ?string $phone = null,
        public readonly ?string $customerNote = null,
        public readonly ?string $fulfillmentStatus = null,
        public readonly ?string $shippingDate = null,
        public readonly ?string $shippedDate = null,
    ) {
    }
}
