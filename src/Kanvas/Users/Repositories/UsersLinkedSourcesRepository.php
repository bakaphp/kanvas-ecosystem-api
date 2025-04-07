<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Enums\SourceEnum;
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

    public static function getAppleLinkedSource(UserInterface $user): array
    {
        return self::getLinkedSourceByPlatform($user, SourceEnum::IOS);
    }

    public static function getAndroidLinkedSource(UserInterface $user): array
    {
        return self::getLinkedSourceByPlatform($user, SourceEnum::ANDROID);
    }

    public static function getLinkedSourceByPlatform(UserInterface $user, SourceEnum $platform): array
    {
        try {
            // Retrieve all sources linked to the platform for the given user
            $linkedSources = UserLinkedSources::where('users_id', $user->getId())
                ->notDeleted()
                ->where('source_id', Sources::getByName($platform->value)->getId())
                ->get();

            if ($linkedSources->isEmpty()) {
                throw new ExceptionsModelNotFoundException("User has not linked a {$platform->value} device");
            }

            return $linkedSources->pluck('source_users_id_text')->toArray();
        } catch (ModelNotFoundException $e) {
            throw new ExceptionsModelNotFoundException("User has not linked a {$platform->value} device");
        }
    }
}
