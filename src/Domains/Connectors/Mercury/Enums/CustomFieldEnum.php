<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

enum CustomFieldEnum: string
{
    case ACCOUNT_ID = 'MERCURY_ACCOUNT_ID';
    case TRANSACTION_ID = 'MERCURY_TRANSACTION_ID';
    case CARD_ID = 'MERCURY_CARD_ID';

    /** On a Guild Organization — the Mercury AR customer it maps to. Presence means "already pushed". */
    case CUSTOMER_ID = 'MERCURY_CUSTOMER_ID';

    /** On a Scribe Invoice — the Mercury AR invoice we created from it. Presence means "already pushed". */
    case INVOICE_ID = 'MERCURY_INVOICE_ID';
}
