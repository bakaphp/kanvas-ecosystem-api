<?php

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class Welcome extends Notification
{
    public ?string $templateName = 'welcome';

    /**
     * via.
     */
    public function via(): array
    {
        return [...parent::via(), 'mail'];
    }
}
