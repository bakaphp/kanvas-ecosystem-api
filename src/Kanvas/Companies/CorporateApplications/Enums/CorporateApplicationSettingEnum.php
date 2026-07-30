<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Baka\Contracts\AppInterface;

/**
 * App settings driving the approval flow. Read with a fallback to the `movipass_corporate_*`
 * names the flow shipped with, so an app configured before the move keeps working untouched.
 */
enum CorporateApplicationSettingEnum: string
{
    case WELCOME_TEMPLATE = 'corporate_application_welcome_template';
    case REJECTED_TEMPLATE = 'corporate_application_rejected_template';
    case INVITE_LINK_BASE = 'corporate_application_invite_link_base';

    public function legacyKey(): string
    {
        return 'movipass_corporate_' . str_replace('corporate_application_', '', $this->value);
    }

    public function readFrom(AppInterface $app): mixed
    {
        return $app->get($this->value) ?? $app->get($this->legacyKey());
    }
}
