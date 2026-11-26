<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentReceipts\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Payments\Models\Payment;
use Spatie\LaravelData\Data;

class PaymentReceipt extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Payment $payment,
        public readonly ?string $payerBank = null,
        public readonly ?string $instrumentNumber = null,
        public readonly ?Carbon $instrumentDate = null,
    ) {
    }
}
