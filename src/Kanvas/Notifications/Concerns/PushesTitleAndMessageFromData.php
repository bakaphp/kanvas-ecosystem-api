<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Concerns;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Template-free push: the title and message come straight from the notification's data, bypassing the
 * per-app push templates the base toOneSignal() renders.
 */
trait PushesTitleAndMessageFromData
{
    /**
     * @return array<string, mixed>
     */
    public function toOneSignal(UserInterface|AnonymousNotifiable $notifiable): array
    {
        if (! $notifiable instanceof UserInterface) {
            return [];
        }

        return [
            'user_id' => $notifiable->getId(),
            'title' => $this->data['title'] ?? '',
            'message' => $this->data['message'] ?? '',
            'subtitle' => '',
            'apps_id' => $this->app->getId(),
            'data' => $this->getData(),
        ];
    }
}
