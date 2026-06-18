<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Spatie\LaravelData\Data;

class Account extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $account_number,
        public readonly string $name,
        public readonly AccountTypeEnum $account_type,
        public readonly string $currency = 'USD',
        public readonly ?string $description = null,
        public readonly ?AccountSubTypeEnum $account_sub_type = null,
        public readonly ?int $parent_account_id = null,
        public readonly bool $is_active = true,
        public readonly bool $is_system = false,
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
