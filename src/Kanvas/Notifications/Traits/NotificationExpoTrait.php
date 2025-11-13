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
        $additionalData = $this->getData();

        unset($additionalData['apps_id'],
            $additionalData['entity'],
            $additionalData['app'],
            $additionalData['options'],
            $additionalData['fromUser'],
            $additionalData['via'],
            $additionalData['email_template'],
            $additionalData['push_template'],
            $additionalData['company'],
            $additionalData['user']);

        // More aggressive cleanup - remove ALL model instances
        $filtered = [];
        foreach ($additionalData as $key => $value) {
            if (! is_object($value) || is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        $expoMessage = ExpoMessage::create($messageContent['title'])
          ->body($messageContent['message'])
          ->data($filtered)
          ->expiresAt(now()->addHour())
          ->priority('high')
          ->playSound();

        if (! empty($messageContent['subtitle'])) {
            $expoMessage->subtitle($messageContent['subtitle']);
        }

        return $expoMessage;
    }
}
