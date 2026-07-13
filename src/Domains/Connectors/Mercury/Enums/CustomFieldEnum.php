<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Enums;

enum CustomFieldEnum: string
{
    /** On a Guild Organization — the Mercury AR customer it maps to. Presence means "already pushed". */
    case CUSTOMER_ID = 'MERCURY_CUSTOMER_ID';

    /** On a Scribe Invoice — the Mercury AR invoice we created from it. Presence means "already pushed". */
    case INVOICE_ID = 'MERCURY_INVOICE_ID';

    /**
     * On a Scribe Invoice — when we cancelled its Mercury copy.
     *
     * INVOICE_ID deliberately survives a cancellation (the Mercury record survives too, so the link should),
     * which means it can't double as "still live". This can: a second cancel is a 400 from Mercury, and the
     * activity re-fires on every later touch of a voided invoice.
     */
    case INVOICE_CANCELLED_AT = 'MERCURY_INVOICE_CANCELLED_AT';
}
