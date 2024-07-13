<?php

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;

class Welcome extends Notification
{
    public ?string $templateName = EmailTemplateEnum::WELCOME->value;
}
