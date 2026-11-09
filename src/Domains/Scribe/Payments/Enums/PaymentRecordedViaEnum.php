<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Enums;

/**
 * Who told us a bill was paid when the money moved outside Kanvas. Stored on `payments.recorded_via`;
 * a value there is also what marks the payment as one the bank still has to confirm.
 */
enum PaymentRecordedViaEnum: string
{
    case UI = 'ui';
    case AGENT = 'agent';
    case WEBHOOK = 'webhook';
}
