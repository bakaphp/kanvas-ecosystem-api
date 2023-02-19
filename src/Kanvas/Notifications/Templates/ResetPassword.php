<?php

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class ResetPassword extends Notification
{
    public ?string $templateName = 'reset-password';

    /**
     * via.
     *
     * @return array
     */
    public function via(): array
    {
        return [...parent::via(), 'mail'];
    }

    public function getData(): array
    {
        return [
           ...parent::getData(),
            'resetUrl' => $this->app->url . '/users/reset-password/' . $this->toUser->user_activation_forgot,
        ];
    }
}
