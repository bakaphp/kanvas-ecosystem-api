<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\CorporateApplications\Concerns\HasLegacyCorporateKey;

enum CorporateApplicationSettingEnum: string
{
    use HasLegacyCorporateKey;

    case WELCOME_TEMPLATE = 'corporate_application_welcome_template';
    case REJECTED_TEMPLATE = 'corporate_application_rejected_template';
    case INVITE_LINK_BASE = 'corporate_application_invite_link_base';
    case RECEIVER_ID = 'corporate_application_receiver_id';
    case AUTO_APPROVE = 'corporate_application_auto_approve';
    case SLA_HOURS = 'corporate_application_sla_hours';
    case OVERDUE_TEMPLATE = 'corporate_application_overdue_template';

    public function readFrom(AppInterface $app, mixed $default = null): mixed
    {
        $value = $this->readKeyFrom($app);

        return $value === null || $value === '' ? $default : $value;
    }
}
