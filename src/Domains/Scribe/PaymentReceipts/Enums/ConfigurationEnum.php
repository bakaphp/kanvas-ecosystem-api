<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentReceipts\Enums;

enum ConfigurationEnum: string
{
    // Company-level name printed under "Recibido por" on every payment receipt. Blank when unset.
    case RECEIVED_BY = 'payment-receipt-received-by';

    // Company file names (uploaded like the logo, as "photo") for the signature and seal printed on the receipt.
    case SIGNATURE_FILE = 'payment_receipt_signature';
    case SEAL_FILE = 'payment_receipt_seal';
}
