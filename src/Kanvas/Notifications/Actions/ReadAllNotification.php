<?php

declare(strict_types=1);
namespace Kanvas\Notifications\Actions;

use Kanvas\Users\Models\Users;

class ReadAllNotification
{
    public Users $user;

    /**
     * __construct
     *
     * @param  Users $user
     * @return void
     */
    public function __construct(Users $user)
    {
        $this->user = $user;
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->user->unReadNotification() as $notification) {
            $notification->markAsRead();
        }
    }
}
