<?php

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class ResetPassword extends Notification
{
    public ?string $templateName = 'reset-password';

    public function getData(): array
    {
        //replace url for app link
        return [
           ...parent::getData(),
            'resetUrl' => $this->app->get('url') . '/reset-password/' . $this->toUser->getAppProfile($this->app)->user_activation_forgot,
        ];
    }
}
