<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Enums;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Guild\Leads\Models\LeadReceiver;

enum CorporateApplicationEmailEnum: string
{
    case WELCOME = 'welcome';
    case NEEDS_REVIEW = 'needs_review';
    case REJECTED = 'rejected';
    case OVERDUE = 'overdue';

    public function receiverKey(): string
    {
        return 'application_' . $this->value . '_template';
    }

    public function defaultTemplate(): string
    {
        return 'corporate-' . str_replace('_', '-', $this->value);
    }

    public function templateFor(?LeadReceiver $receiver, AppInterface $app): string
    {
        return Field::receiverString($receiver, $this->receiverKey())
            ?? Str::trimToNull((string) $this->appSetting()->readFrom($app))
            ?? $this->defaultTemplate();
    }

    private function appSetting(): Setting
    {
        return match ($this) {
            self::WELCOME => Setting::WELCOME_TEMPLATE,
            self::NEEDS_REVIEW => Setting::NEEDS_REVIEW_TEMPLATE,
            self::REJECTED => Setting::REJECTED_TEMPLATE,
            self::OVERDUE => Setting::OVERDUE_TEMPLATE,
        };
    }
}
