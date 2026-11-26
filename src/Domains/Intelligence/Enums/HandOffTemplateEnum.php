<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

/**
 * Tenants already store their own templates under these names, so a value here is never renamed.
 */
enum HandOffTemplateEnum: string
{
    case EMAIL = 'lead_handoff';
    case PUSH = 'lead_handoff_push_notification';
    case SMS = 'lead_handoff_sms_notification';
    case DATABASE = 'lead_handoff_db';
    case COMPLIANCE_EMAIL = 'lead_handoff_compliance_handoff';
    case COMPLIANCE_PUSH = 'lead_handoff_compliance_push_notification';
    case COMPLIANCE_SMS = 'lead_handoff_compliance_sms_notification';
}
