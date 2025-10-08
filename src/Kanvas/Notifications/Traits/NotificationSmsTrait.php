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

        $phone = $this->toUser->cell_phone_number ?? $this->toUser->phone_number;

        if (empty($phone)) {
            return [];
        }

        return [
            'user_id' => $this->toUser->getId(),
            'phone' => $phone,
            'content' => $this->message(),
            'title' => $this->subject ?? '',
            'app' => $this->app,
            'company' => $this->company,
        ];
    }
}
