<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Exceptions\ValidationException;
use NotificationChannels\Expo\ExpoMessage;

trait NotificationExpoTrait
{
    /**
     * toKanvasDatabase.
     */
    public function toExpo(UserInterface|AnonymousNotifiable $notifiable): ExpoMessage
    {
        $this->toUser = $notifiable instanceof UserInterface ? $notifiable : null;

        if ($this->toUser == null) {
            throw new ValidationException('User not found');
        }

        $messageContent = Str::cleanJsonString($this->getPushTemplate());
        if (! Str::isJson($messageContent)) {
            report('Message content for push notification is not a valid JSON ' . json_encode($messageContent));

            throw new ValidationException('Message content for push notification is not a valid JSON');
        }

        $messageContent = json_decode($messageContent, true);
        $expoMessage = ExpoMessage::create($messageContent['title'])
          ->body($messageContent['message'])
          ->data($this->getData())
          ->expiresAt(now()->addHour())
          ->priority('high')
          ->playSound();

        if (! empty($messageContent['subtitle'])) {
            $expoMessage->subtitle($messageContent['subtitle']);
        }

        return $expoMessage;
    }
}
