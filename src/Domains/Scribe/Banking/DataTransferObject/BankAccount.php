<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Spatie\LaravelData\Data;

class BankAccount extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $account_name,
        public readonly int $gl_account_id,
        public readonly string $currency,
        public readonly ?string $account_number_last4 = null,
        public readonly ?string $routing_number_masked = null,
        public readonly ?string $institution_name = null,
        public readonly bool $is_active = true,
        public readonly string $source = 'kanvas',
        public readonly ?string $external_id = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
