<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Observers;

use Kanvas\Social\Follows\Models\UsersFollows;

class UserFollowObserver
{
    public function created(UsersFollows $userFollow)
    {
        $userFollow->updateSocialCount();
    }

    public function deleted(UsersFollows $userFollow)
    {
        $userFollow->decreaseSocialCount();
    }
}
