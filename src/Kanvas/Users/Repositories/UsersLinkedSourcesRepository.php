<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Enums\SourceEnum;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Users\Models\Sources;
use Kanvas\Users\Models\UserLinkedSources;

class UsersLinkedSourcesRepository
{
    /**
     * Get record by users_id
     */
    public static function getByUsersId(int $usersId): UserLinkedSources
    {
        return UserLinkedSources::where('users_id', $usersId)
            ->notDeleted()
            ->firstOrFail();
    }

    public static function getAppleLinkedSource(UserInterface $user): UserLinkedSources
    {
        try {
            return UserLinkedSources::where('users_id', $user->getId())
                ->notDeleted()
                ->where('source_id', Sources::getByName(SourceEnum::IOS->value)->getId())
                ->firstOrFail();
        } catch(ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('User has not linked an apple device');
        }
    }

    public static function getAndroidLinkedSource(UserInterface $user): UserLinkedSources
    {
        try {
            return UserLinkedSources::where('users_id', $user->getId())
                ->notDeleted()
                ->where('source_id', Sources::getByName(SourceEnum::ANDROID->value)->getId())
                ->firstOrFail();
        } catch(ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException('User has not linked an android device');
        }
    }
}
