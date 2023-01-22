<?php
declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Notifications\DataTransferObject\Notifications as NotificationsDto;
use Kanvas\Notifications\Models\Notifications as NotificationsModel;

class CreateNotification
{
    /**
     * __construct.
     *
     * @param  NotificationsDto $dto
     *
     * @return void
     */
    public function __construct(NotificationsDto $dto)
    {
        $this->dto = $dto;
    }

    /**
     * execute.
     *
     * @return void
     */
    public function execute(): void
    {
        $notification = new NotificationsModel();
        $notification->fill((array) $this->dto);
        $notification->saveOrFail();
    }
}
