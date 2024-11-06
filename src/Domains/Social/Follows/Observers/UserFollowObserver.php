<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Observers;

use Kanvas\Social\Follows\Models\UsersFollows;

class UserFollowObserver
{
    public function saved(UsersFollows $userFollow)
    {
        if ($userFollow->is_deleted == 0) {
            $userFollow->updateSocialCount();
        } else {
            $userFollow->decreaseSocialCount();
        }
    }

    public function deleted(UsersFollows $userFollow)
    {
        $userFollow->decreaseSocialCount();
    }
}
