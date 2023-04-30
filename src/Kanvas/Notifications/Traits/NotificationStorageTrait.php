<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Users\Contracts\UserInterface;
use Exception;

trait NotificationStorageTrait
{
    /**
     * toKanvasDatabase.
     */
    public function toKanvasDatabase(UserInterface $notifiable): array
    {
        $this->toUser = $notifiable;

        try {
            $fromUserId = $this->getFromUser()->getId();
        } catch (Exception $e) {
            //for now, we need to clean this up -_-
            $fromUserId = 0;
        }

        return [
            'users_id' => $notifiable->getId(),
            'from_users_id' => $fromUserId,
            'companies_id' => $notifiable->getCurrentCompany()->getId(),
            'apps_id' => $this->app->getId(),
            'system_modules_id' => $this->getType()->system_modules_id,
            'notification_type_id' => $this->getType()->getId(),
            'entity_id' => method_exists($this->entity, 'getId') ? $this->entity->getId() : $this->entity->id,
            'content' => $this->message(),
            'read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ];
    }
}
