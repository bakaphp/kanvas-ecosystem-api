<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

class ChangePasswordUserLogged extends Notification
{
    public ?string $templateName = 'change-password';
}
