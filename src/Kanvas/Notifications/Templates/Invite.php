<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;
use Override;

class Invite extends Notification
{
    public ?string $templateName = EmailTemplateEnum::USER_INVITE->value;

    #[Override]
    public function getData(): array
    {
        return [
           ...parent::getData(),
            'fromUser' => $this->getFromUser(),
        ];
    }
}
