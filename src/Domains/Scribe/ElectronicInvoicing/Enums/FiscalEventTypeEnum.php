<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Enums;

enum FiscalEventTypeEnum: string
{
    case AUTHORITY_ANSWER = 'authority_answer';
    case CANCELLATION = 'cancellation';
    case INVALIDATION = 'invalidation';
    case RECEIPT_ACKNOWLEDGEMENT = 'receipt_acknowledgement';
    case COMMERCIAL_RESPONSE = 'commercial_response';
    case CONTINGENCY = 'contingency';
}
