<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

trait CanBeNotifiedTrait
{
    public function hasUserBeenNotified(UserInterface $user, ?AppInterface $app = null): bool
    {
        $app = $this->app ?? $app ?? app(Apps::class);
        $systemModule = SystemModulesRepository::getByModelName(get_class($this), $app);

        return Notifications::where('users_id', $user->getId())
            ->where('system_modules_id', $systemModule->getId())
            ->where('entity_id', $this->getId())
            ->fromApp($app)
            ->notDeleted()
            ->exists();
    }
}
