<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Regions\Models\Regions;
use Spatie\LaravelData\Data;

class GooglePlayInAppPurchaseReceipt extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Regions $region,
        public readonly string $product_id,
        public readonly string $order_id,
        public readonly string $purchase_token,
        public readonly int $purchase_time,
        public readonly int $purchase_state, // 0 = Purchased, 1 = Canceled, 2 = Pending
        public readonly array $custom_fields = []
    ) {}

    public static function fromMultiple(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        Regions $region,
        array $data,
    ): self {
        return new self(
            $app,
            $company,
            $user,
            $region,
            $data['product_id'],
            $data['order_id'],
            $data['purchase_token'],
            (int) $data['purchase_time'],
            (int) $data['purchase_state'],
            $data['custom_fields'] ?? []
        );
    }
}
