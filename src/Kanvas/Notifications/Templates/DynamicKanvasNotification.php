<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Templates;

use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Notification;

class DynamicKanvasNotification extends Notification
{
    /**
     * __construct
     */
    public function __construct(
        NotificationTypes $notificationType,
        array $data = [],
    ) {
        parent::__construct($notificationType, $data);
        $this->type = $notificationType;
        $this->setTemplateName($notificationType->template);
        $this->setData($data);
        $this->channels = $notificationType->getNotificationChannels();
    }
}
