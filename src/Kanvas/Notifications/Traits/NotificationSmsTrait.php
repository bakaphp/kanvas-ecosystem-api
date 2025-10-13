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

        if ($this->toUser == null) {
            return [];
        }

        $appUser = $this->toUser->getAppProfile($this->app);
        $phone = $appUser->two_step_phone_number ?? $this->toUser->cell_phone_number ?? $this->toUser->phone_number;

        if (empty($phone)) {
            return [];
        }

        return [
            'user_id' => $this->toUser->getId(),
            'phone' => $phone,
            'content' => $this->getSmsTemplate(),
            'title' => $this->subject ?? '',
            'app' => $this->app,
            'company' => $this->company,
        ];
    }
}
