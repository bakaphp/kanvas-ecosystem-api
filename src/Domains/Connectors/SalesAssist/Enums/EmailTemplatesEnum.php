<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Enums;

enum EmailTemplatesEnum: string
{
    // Email sent to the company when a new lead is created via receivers
    case LEAD_COMPANY_EMAIL = 'lead-company-email';
}
