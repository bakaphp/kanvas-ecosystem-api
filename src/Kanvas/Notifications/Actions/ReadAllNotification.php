<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Users\Models\Users;

class ReadAllNotification
{
    /**
     * __construct.
     */
    public function __construct(public Users $user)
    {
        $this->user = $user;
    }

    /**
     * execute.
     */
    public function execute(): void
    {
        Notifications::where('users_id', $this->user->id)
            ->where('is_deleted', 0)
            ->where('apps_id', app(Apps::class)->getId())
            ->where('read', 0)
            ->update(['read' => 1]);
    }
}
