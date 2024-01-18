<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;

/**
 * @deprecated version 2 , move to DynamicKanvasNotification
 */
class ChangeEmailUserLogged extends Notification
{
    public ?string $templateName = 'user-email-update';
}
