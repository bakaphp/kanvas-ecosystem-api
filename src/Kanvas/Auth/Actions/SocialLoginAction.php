<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Socialite\DataTransferObject\User as SocialiteUser;
use Kanvas\Users\Models\Sources;
use Kanvas\Users\Models\UserLinkedSources;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class SocialLoginAction
{
    /**
     * Construct function.
     */
    public function __construct(
        protected SocialiteUser $socialUser,
        protected string $provider,
        protected Apps $app
    ) {
    }

    /**
     * Login a user and create if not exist.
     *
     * @param SocialiteUser $socialUser
     * @param string $provider
     */
    public function execute(): Users
    {
        $source = Sources::where('title', $this->provider)->firstOrFail();
        $userLinkedSource = UserLinkedSources::where('source_users_id', $this->socialUser->id)->where('source_id', $source->id)->first();

        if (! $userLinkedSource) {
            try {
                $existedUser = UsersRepository::getUserOfAppByEmail($this->socialUser->email, $this->app);
            } catch (ModelNotFoundException $e) {
                $userData = [
                    'firstname' => $this->socialUser->name,
                    'email' => $this->socialUser->email,
                    'password' => Str::random(11),
                    'displayname' => $this->socialUser->nickname,
                ];
                $userData = RegisterInput::fromArray($userData);

                $registeredUser = new RegisterUsersAction($userData, $this->app);
                $existedUser = $registeredUser->execute();
            }

            //$userAppProfile = $existedUser->getAppProfile($this->app);
            //$userLinkedSource = UserLinkedSources::createSocial($this->socialUser, $existedUser, $source);
            UserLinkedSources::firstOrCreate([
                'users_id' => $existedUser->getId(),
                'source_id' => $source->getId(),
                'source_users_id' => $this->socialUser->id,
            ], [
                'source_users_id_text' => $this->socialUser->token,
                'source_username' => $this->socialUser->nickname ?? $this->socialUser->name,
            ]);

            return $existedUser;
        }

        return $userLinkedSource->user;
    }
}
