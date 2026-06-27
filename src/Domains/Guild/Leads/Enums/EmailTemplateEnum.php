<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Enums;

enum EmailTemplateEnum: string
{
    case NEW_LEAD = 'new-lead';
    case NEW_LEAD_COMPANY_ADMIN = 'new-lead-company-admin';
    case LEAD_RECEIVED_CONFIRMATION = 'lead-received-confirmation';
    case LEAD_RECEIVED_CONFIRMATION_SMS = 'lead-received-confirmation-sms';
}
