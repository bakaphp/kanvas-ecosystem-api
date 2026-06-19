<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Items\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Scribe\Items\Enums\ItemTypeEnum;
use Spatie\LaravelData\Data;

class Item extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $item_number,
        public readonly string $name,
        public readonly ItemTypeEnum $type,
        public readonly ?string $description = null,
        public readonly ?int $inventory_variant_id = null,
        public readonly ?int $default_income_account_id = null,
        public readonly ?int $default_expense_account_id = null,
        public readonly ?int $default_tax_code_id = null,
        public readonly ?float $default_price_native = null,
        public readonly ?string $currency = null,
        public readonly bool $is_active = true,
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
