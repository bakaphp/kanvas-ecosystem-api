<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\DataTransferObject;

use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Payments\Models\Payment;

final class RecordedBillPayment
{
    public function __construct(
        public readonly BillPaymentAllocation $allocation,
        public readonly Payment $payment,
        public readonly bool $alreadyRecorded,
    ) {
    }
}
