<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Notification as NotificationsNotification;
use Kanvas\Users\Models\Users;

class NotifyLeadStakeholdersService
{
    public function __construct(
        protected Lead $lead,
        protected NotificationsNotification $notification,
    ) {
    }

    public function all(): void
    {
        $this->owner();
        $this->managers();
        $this->followers();
    }

    public function owner(): void
    {
        if ($this->lead->owner) {
            $this->lead->owner->notify($this->notification);
        }
    }

    public function managers(?string $emailContent = null): void
    {
        $companyManagers = $this->lead->company->get('company_manager');

        if ($this->lead->get('sent_email_notification_to_manager')) {
            return;
        }

        if ($companyManagers && is_array($companyManagers)) {
            $this->lead->set('sent_email_notification_to_manager', 1);

            // Get all users in one query and send bulk notification
            $users = Users::whereIn('id', $companyManagers)->get();

            Notification::send($users, $this->notification);
        }
    }

    public function followers(): void
    {
        //notify followers
        if ($this->lead->getTotalFollowers($this->lead->app) === 0) {
            return;
        }
        foreach ($this->lead->followers as $follower) {
            if ($follower->id !== $this->lead->owner->getId()) {
                $follower->notify($this->notification);
            }
        }
    }
}
