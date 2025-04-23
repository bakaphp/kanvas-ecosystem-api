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

        // Create a filtered copy of the data array without the keys we want to exclude
        $filteredData = [];
        if (isset($this->data) && is_array($this->data)) {
            $keysToExclude = [
                'apps_id',
                'entity',
                'app',
                'options',
                'fromUser',
                'company',
                'via',
                'user',
            ];

            foreach ($this->data as $key => $value) {
                if (! in_array($key, $keysToExclude)) {
                    $filteredData[$key] = $value;
                }
            }
        }

        return [
            'users_id'             => $userId,
            'from_users_id'        => $fromUserId,
            'companies_id'         => $companiesId,
            'apps_id'              => $this->app->getId(),
            'system_modules_id'    => $this->getType()->system_modules_id,
            'notification_type_id' => $this->getType()->getId(),
            'entity_id'            => method_exists($this->entity, 'getId') ? $this->entity->getId() : $this->entity->id,
            'content'              => $this->message(),
            'entity_content'       => $filteredData ?? [],
            'read'                 => 0,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
            'is_deleted'           => 0,
        ];
    }
}
