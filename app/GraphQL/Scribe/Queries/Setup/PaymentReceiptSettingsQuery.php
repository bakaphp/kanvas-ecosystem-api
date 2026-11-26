<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Queries\Setup;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Scribe\PaymentReceipts\Services\PaymentReceiptSettingsService;

class PaymentReceiptSettingsQuery
{
    use ResolvesActingContext;

    public function current(): array
    {
        return new PaymentReceiptSettingsService()->current($this->actingContext()->company);
    }
}
