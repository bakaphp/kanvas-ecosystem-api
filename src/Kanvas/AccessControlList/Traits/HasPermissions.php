<?php

declare(strict_types=1);

namespace Baka\Traits;

namespace Kanvas\AccessControlList\Traits;

use Baka\Users\Contracts\UserInterface;

trait HasPermissions
{
    public function canEdit(UserInterface $user): bool
    {
        return $this->users_id == $user->getId() || $user->isAdmin();
    }

    public function canDelete(UserInterface $user): bool
    {
        return $this->users_id == $user->getId() || $user->isAdmin();
    }
}
