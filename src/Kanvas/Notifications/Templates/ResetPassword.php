<?php

namespace Kanvas\Notifications\Templates;

use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Notifications\Notification;

/**
 * @deprecated version 2 , move to DynamicKanvasNotification
 */
class ResetPassword extends Notification
{
    public ?string $templateName = 'reset-password';

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
