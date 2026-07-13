<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;

trait NotificationSmsTrait
{
    public function toSms(UserInterface|AnonymousNotifiable $notifiable): array
    {
        $this->toUser = $notifiable instanceof UserInterface ? $notifiable : null;

        $phone = $this->resolveSmsRecipientPhone($notifiable);

        if (empty($phone)) {
            return [];
        }

        return [
            'user_id' => $this->toUser?->getId(),
            'phone' => $phone,
            'content' => $this->getSmsTemplate(),
            'title' => $this->subject ?? '',
            'app' => $this->app,
            'company' => $this->company,
        ];
    }

    /**
     * Anonymous notifiables (e.g. a lead contact with no user account) carry their phone in the
     * `sms` route; registered users resolve it from their app profile / contact columns.
     */
    private function resolveSmsRecipientPhone(UserInterface|AnonymousNotifiable $notifiable): ?string
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routeNotificationFor('sms');

            return is_string($route) && $route !== '' ? $route : null;
        }

        $appUser = $notifiable->getAppProfile($this->app);

        return $appUser->two_step_phone_number ?? $notifiable->cell_phone_number ?? $notifiable->phone_number;
    }
}
