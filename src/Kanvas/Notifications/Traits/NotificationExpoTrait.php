<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use NotificationChannels\Expo\ExpoMessage;

trait NotificationExpoTrait
{
    private ?array $expoContent = null;

    public function toExpo(UserInterface|AnonymousNotifiable $notifiable): ExpoMessage
    {
        $this->toUser = $notifiable instanceof UserInterface ? $notifiable : null;

        if ($this->toUser == null) {
            throw new ValidationException('User not found');
        }

        $content = $this->resolveExpoContent();

        if (empty($content['message'])) {
            throw new ValidationException('Push notification has no message');
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
            //this will escape any obj or array values
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

    protected function hasExpoContent(): bool
    {
        try {
            $content = $this->resolveExpoContent();
        } catch (ModelNotFoundException) {
            $content = [];
        }

        if (! empty($content['message'])) {
            return true;
        }

        Log::warning('Skipping expo push notification, rendered message is empty', [
            'notification' => static::class,
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company?->getId(),
        ]);

        return false;
    }

    private function resolveExpoContent(): array
    {
        return $this->expoContent ??= $this->getPushContent();
    }
}
