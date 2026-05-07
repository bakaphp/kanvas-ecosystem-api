<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Users\Models\Users;

class ReadAllNotificationAction
{
    /**
     * __construct.
     */
    public function __construct(
        public Users $user,
        public ?AppInterface $app = null,
    ) {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * execute.
     */
    public function execute(): void
    {
        Notifications::where('users_id', $this->user->id)
            ->where('is_deleted', 0)
            ->fromApp($this->app)
            ->where('read', 0)
            ->update(['read' => 1]);

        // Reset unread notifications count to 0
        $userProfile = $this->user->getAppProfile($this->app);
        $userProfile->update(['unread_notifications_count' => 0]);
    }
}
