<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Souk\Payments\Models\Payments;
use Spatie\LaravelData\Data;

class RefundPayment extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Payments $payment,
        public readonly float $amount,
        public readonly ?string $reason = null,
        public readonly ?string $processorRefundId = null,
    ) {
    }
}
