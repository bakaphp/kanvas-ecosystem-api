<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Notifications\DataTransferObject\NotificationType;
use Kanvas\Notifications\Models\NotificationTypes;

class CreateNotificationTypeAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected NotificationType $notificationType,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): NotificationTypes
    {
        return NotificationTypes::firstOrCreate([
            'name' => $this->notificationType->name,
            'template' => $this->notificationType->template->name,
            'apps_id' => $this->notificationType->app->getId(),
        ], [
            'description' => $this->notificationType->description,
            'key' => $this->notificationType->name,
            'weight' => $this->notificationType->weight,
            'system_modules_id' => 1, //deprecated
            'parent_id' => 0
        ]);
    }
}
