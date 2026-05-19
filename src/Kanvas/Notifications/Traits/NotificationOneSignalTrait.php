<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;

trait NotificationOneSignalTrait
{
    public function toOneSignal(UserInterface|AnonymousNotifiable $notifiable): array
    {
        $this->toUser = $notifiable instanceof UserInterface ? $notifiable : null;

        if ($this->toUser == null) {
            return [];
        }

        $content = $this->getPushContent();
        $content['title'] = html_entity_decode($content['title'], ENT_QUOTES, 'UTF-8');
        $content['message'] = html_entity_decode($content['message'], ENT_QUOTES, 'UTF-8');

        if ($content['subtitle'] !== null) {
            $content['subtitle'] = html_entity_decode($content['subtitle'], ENT_QUOTES, 'UTF-8');
        }

        if ($content['title'] === '' && $content['message'] === '') {
            return [];
        }

        return [
            'user_id' => $this->toUser->getId(),
            'message' => $content['message'],
            'title' => $content['title'],
            'subtitle' => $content['subtitle'] ?? '',
            'apps_id' => $this->app->getId(),
            'data' => $this->getData(),
        ];
    }
}
