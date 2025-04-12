<?php

namespace Kanvas\Notifications\Templates;

use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Override;

/**
 * @deprecated version 2 , move to DynamicKanvasNotification
 */
class ResetPassword extends Notification
{
    public ?string $templateName = EmailTemplateEnum::RESET_PASSWORD->value;

    #[Override]
    public function getData(): array
    {
        //replace url for app link
        $resetUrl = $this->app->get(AppSettingsEnums::RESET_LINK_URL->getValue()) ?? $this->app->url . '/reset-password';

        return [
           ...parent::getData(),
            'resetUrl' => $resetUrl . '/' . $this->toUser->getAppProfile($this->app)->user_activation_forgot,
        ];
    }
}
