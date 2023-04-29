<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class Invite extends Notification
{
    public ?string $templateName = 'users-invite';

    /**
     * via.
     */
    public function via(object $notifiable): array
    {
        return [...parent::via($notifiable), 'mail'];
    }

    public function getData(): array
    {
        return [
           ...parent::getData(),
            'fromUser' => $this->getFromUser(),
        ];
    }
}
