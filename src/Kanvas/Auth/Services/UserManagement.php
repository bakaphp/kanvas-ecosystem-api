<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Users\Models\Sources;
use Kanvas\Users\Models\UserLinkedSources;
use Kanvas\Users\Models\Users;
use Laravel\Socialite\Two\User as SocialiteUser;
use Illuminate\Support\Str;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;

class UserManagement
{
    protected Apps $app;

    /**
     * Construct function.
     */
    public function __construct(
        protected Users $user
    ) {
        $this->app = app(Apps::class);
    }

    /**
     * Update current user data with $data
     *
     * @param array $data
     *
     * @return Users
     */
    public function update(array $data): Users
    {
        try {
            $this->user->update(array_filter($data));
        } catch (InternalServerErrorException $e) {
            throw new InternalServerErrorException($e->getMessage());
        }

        return $this->user;
    }
}
