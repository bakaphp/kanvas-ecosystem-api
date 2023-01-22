<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Notifications\Models\Notifications;
use Kanvas\Users\Models\Users;

class ReadAllNotification
{
    public Users $user;

    /**
     * __construct.
     *
     * @param  Users $user
     *
     * @return void
     */
    public function __construct(Users $user)
    {
        $this->user = $user;
    }

    /**
     * execute.
     *
     * @return void
     */
    public function execute(): void
    {
        Notifications::where('users_id', $this->user->id)
            ->where('read', 0)
            ->update(['read' => 1]);
    }
}
