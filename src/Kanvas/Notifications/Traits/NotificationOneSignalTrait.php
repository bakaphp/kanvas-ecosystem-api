<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Exceptions\ValidationException;

trait NotificationOneSignalTrait
{
    /**
     * toKanvasDatabase.
     */
    public function toOneSignal(UserInterface|AnonymousNotifiable $notifiable): array
    {
        $this->toUser = $notifiable instanceof UserInterface ? $notifiable : null;

        if ($this->toUser == null) {
            return [];
        }

        $messageContent = $this->getPushTemplate();

        if (! Str::isJson($messageContent)) {
            //throw new ValidationException('Message content for push notification is not a valid JSON');
            return [];
        }

        $messageContent = json_decode($messageContent, true);

        return [
            'user_id' => $this->toUser->getId(),
            'message' => $messageContent['message'],
            'title' => $messageContent['title'] ?? '',
            'subtitle' => $messageContent['subtitle'] ?? '',
            'apps_id' => $this->app->getId(),
            'data' => $this->getData()
        ];
    }
}
