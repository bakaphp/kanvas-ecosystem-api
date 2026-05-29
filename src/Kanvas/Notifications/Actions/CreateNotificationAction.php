<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Notifications\DataTransferObject\Notifications as NotificationsDto;
use Kanvas\Notifications\Models\Notifications as NotificationsModel;

class CreateNotificationAction
{
    public function __construct(
        protected NotificationsDto $dto
    ) {
    }

    public function execute(): void
    {
        $notification = new NotificationsModel();
        $notification->fill($this->dto->toArray());
        $notification->saveOrFail();
    }
}
