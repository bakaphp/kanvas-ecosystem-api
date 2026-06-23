<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Enums;

enum PaymentMethodEnum: string
{
    case WIRE = 'wire';
    case ACH = 'ach';
    case CHECK = 'check';
    case CASH = 'cash';
    case CARD = 'card';
    case STRIPE = 'stripe';
    case MERCURY_MATCH = 'mercury_match';
    case ADM_SYNC = 'adm_sync';
    case MANUAL = 'manual';
    case PDF_INGEST_AUTO = 'pdf_ingest_auto';
}
