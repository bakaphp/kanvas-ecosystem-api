<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Notifications\AnonymousNotifiable;
use Kanvas\Enums\AppEnums;

trait NotificationStorageTrait
{
    /**
     * toKanvasDatabase.
     */
    public function toKanvasDatabase(UserInterface|AnonymousNotifiable $notifiable): array
    {
        $this->toUser = $notifiable instanceof UserInterface ? $notifiable : null;
        $companiesId = AppEnums::GLOBAL_COMPANY_ID->getValue();
        $userId = AppEnums::GLOBAL_USER_ID->getValue();

        try {
            $fromUserId = $this->getFromUser()->getId();
        } catch (Exception $e) {
            //for now, we need to clean this up -_-
            $fromUserId = AppEnums::GLOBAL_USER_ID->getValue();
        }

        if ($notifiable instanceof UserInterface) {
            $companiesId = $this->company instanceof CompanyInterface ? $this->company->getId() : $notifiable->getCurrentCompany()->getId();
            $userId = $notifiable->getId();
        }

        //@todo if content is empty, we should return empty array
        //@todo change to the new notification logic
        unset($this->data['apps_id'],
            $this->data['entity'],
            $this->data['app'],
            $this->data['options'],
            $this->data['fromUser'],
            $this->data['company'],
            $this->data['via'],
            $this->data['user']);

        return [
            'users_id' => $userId,
            'from_users_id' => $fromUserId,
            'companies_id' => $companiesId,
            'apps_id' => $this->app->getId(),
            'system_modules_id' => $this->getType()->system_modules_id,
            'notification_type_id' => $this->getType()->getId(),
            'entity_id' => method_exists($this->entity, 'getId') ? $this->entity->getId() : $this->entity->id,
            'content' => $this->message(),
            'entity_content' => $this->data ?? [],
            'read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_deleted' => 0,
        ];
    }
}
