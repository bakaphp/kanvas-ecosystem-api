<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

trait CanBeNotifiedTrait
{
    public function hasBeenNotified(Model $entity, NotificationTypes $type): bool
    {
        //$app = $this->app ?? $app ?? app(Apps::class);
        //$systemModule = SystemModulesRepository::getByModelName(get_class($entity), $app);
        
        return Notifications::where('users_id', $this->getId())
            ->where('system_modules_id', $type->system_modules_id)
            ->where('entity_id', $entity->getId())
            ->fromApp($type->app)
            ->notDeleted()
            ->exists();
    }
}
