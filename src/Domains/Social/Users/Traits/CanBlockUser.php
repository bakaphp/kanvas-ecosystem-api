<?php

declare(strict_types=1);

namespace Kanvas\Social\Users\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps as ModelsApps;
use Kanvas\Social\Users\Models\BlockUser;

trait CanBlockUser
{
    public function block(UserInterface $blocked, ModelsApps $app): BlockUser
    {
        return DB::transaction(function () use ($blocked, $app) {
            $blockedUser = $this->findBlockedUser($this, $blocked, $app);

            if (! $blockedUser) {
                $blockedUser = new BlockUser();
                $blockedUser->users_id = $this->getId();
                $blockedUser->blocked_users_id = $blocked->getId();
                $blockedUser->apps_id = $app->getId();
                $blockedUser->saveOrFail();
            } else {
                $blockedUser->is_deleted = 0;
                $blockedUser->saveOrFail();
            }

            return $blockedUser;
        });
    }

    public function unBlock(UserInterface $blockedUser, ModelsApps $app): ?BlockUser
    {
        return DB::transaction(function () use ($blockedUser, $app) {
            $blockedUser = $this->findBlockedUser($this, $blockedUser, $app);

            if ($blockedUser) {
                $blockedUser->delete();
            }

            return $blockedUser;
        });
    }

    public function isBlocked(UserInterface $blockedUser, ModelsApps $app): bool
    {
        return $this->findBlockedUser($this, $blockedUser, $app) !== null;
    }

    private function findBlockedUser(UserInterface $user, UserInterface $blockedUser, ModelsApps $app): ?BlockUser
    {
        return BlockUser::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('blocked_users_id', $blockedUser->getId())
            ->withTrashed()
            ->first();
    }
}
