<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum EmailTemplateEnum: string
{
    case NEW_LEAD = 'new-lead';
    case NEW_LEAD_COMPANY_ADMIN = 'new-lead-company-admin';
}
