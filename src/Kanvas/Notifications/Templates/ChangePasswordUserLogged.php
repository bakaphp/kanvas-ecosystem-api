<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;

class ChangePasswordUserLogged extends Notification
{
    public ?string $templateName = EmailTemplateEnum::CHANGE_PASSWORD->value;
}
