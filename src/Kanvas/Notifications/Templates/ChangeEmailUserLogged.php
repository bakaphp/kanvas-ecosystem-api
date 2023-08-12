<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class ChangeEmailUserLogged extends Notification
{
    public ?string $templateName = 'user-email-update';
}
