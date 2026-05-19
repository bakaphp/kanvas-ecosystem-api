<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Exceptions\ValidationException;
use NotificationChannels\Expo\ExpoMessage;

trait NotificationExpoTrait
{
    public function toExpo(UserInterface|AnonymousNotifiable $notifiable): ExpoMessage
    {
        $this->toUser = $notifiable instanceof UserInterface ? $notifiable : null;

        if ($this->toUser == null) {
            throw new ValidationException('User not found');
        }

        $content = $this->getPushContent();

        if ($content['title'] === '' && $content['message'] === '') {
            throw new ValidationException('Push notification has no title or message');
        }

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

        $filtered = [];
        foreach ($additionalData as $key => $value) {
            if (is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        $expoMessage = ExpoMessage::create($content['title'])
          ->body($content['message'])
          // Only call ->data($filtered) when $filtered is non-empty. to avoid expo errors about invalid data payloads.
          ->when(! empty($filtered), fn (ExpoMessage $msg) => $msg->data($filtered))
          ->expiresAt(now()->addHour())
          ->priority('high')
          ->playSound();

        if (! empty($content['subtitle'])) {
            $expoMessage->subtitle($content['subtitle']);
        }

        return $expoMessage;
    }
}
