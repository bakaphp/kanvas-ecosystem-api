<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Observers;

use Kanvas\Notifications\Models\Notifications;

class NotificationObserver
{
    public function created(Notifications $notification): void
    {
        $this->incrementUnreadNotificationsCount($notification);
    }

    public function updated(Notifications $notification): void
    {
        // If notification is marked as read, decrement the count
        if ($notification->wasChanged('read') && $notification->read == 1) {
            $this->decrementUnreadNotificationsCount($notification);
        }
    }

    private function incrementUnreadNotificationsCount(Notifications $notification): void
    {
        $userProfile = $notification->user->getAppProfile($notification->app);
        $userProfile->increment('unread_notifications_count');
    }

    private function decrementUnreadNotificationsCount(Notifications $notification): void
    {
        $userProfile = $notification->user->getAppProfile($notification->app);
        $userProfile->decrement('unread_notifications_count');
    }
}
