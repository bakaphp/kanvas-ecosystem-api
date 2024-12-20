<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Regions\Models\Regions;
use Spatie\LaravelData\Data;

class AppleInAppPurchaseReceipt extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Regions $region,
        public readonly string $product_id, //this is the sku
        public readonly string $transaction_id,
        public readonly string $receipt,
        public readonly int $transaction_date,
        public readonly array $custom_fields = []
    ) {
    }

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
            $data['transaction_id'],
            $data['receipt'],
            (int) $data['transaction_date'],
            $data['custom_fields'] ?? []
        );
    }
}
