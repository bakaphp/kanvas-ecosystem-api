<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\NotificationTypesMessageLogic;

class CreateNotificationTypesMessageLogicAction
{
    /**
     * __construct.
     */
    public function __construct(
        private AppInterface $app,
        private NotificationTypes $notificationType,
        private string $logic
    ) {
    }

    /**
     * @psalm-suppress MixedInferredReturnType
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): NotificationTypesMessageLogic
    {
        return NotificationTypesMessageLogic::create([
            'apps_id' => $this->app->getId(),
            'notifications_type_id' => $this->notificationType->getId(),
            'logic' => $this->logic,
        ]);
    }
}
