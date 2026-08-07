<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceStatusEnum: string
{
    case QUOTED = 'quoted';
    case DOCUMENTS_UPLOADED = 'documents_uploaded';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PAID = 'paid';
    case EMITTED = 'emitted';
    case POLICY_ACTIVE = 'policy_active';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
}
