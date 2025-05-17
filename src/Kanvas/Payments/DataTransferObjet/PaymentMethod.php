<?php

declare(strict_types=1);

namespace Kanvas\Payments\DataTransferObjet;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;

use Spatie\LaravelData\Data;

class PaymentMethod extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly UserInterface $user,
        public readonly CompanyInterface $company,
        public readonly string $payment_ending_numbers,
        public readonly string $payment_methods_brand,
        public readonly string $expiration_date,
        public readonly string $stripe_card_id,
        public readonly string $zip_code,
        public readonly bool $is_default = false,
        public readonly bool $is_deleted = false,
        public readonly string $processor = "stripe",
        public readonly mixed $metadata = [],
    ) {
    }
}
