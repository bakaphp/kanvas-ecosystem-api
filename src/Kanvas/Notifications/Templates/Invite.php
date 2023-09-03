<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class Invite extends Notification
{
    public ?string $templateName = 'users-invite';

    public function getData(): array
    {
        return [
           ...parent::getData(),
            'fromUser' => $this->getFromUser(),
        ];
    }
}
