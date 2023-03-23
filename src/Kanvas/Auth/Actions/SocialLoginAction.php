<?php

declare(strict_types=1);

namespace Kanvas\Auth\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Users\Models\Users;
use Laravel\Socialite\Two\User as SocialiteUser;
use Kanvas\Users\Models\Sources;
use Kanvas\Users\Models\UserLinkedSources;
use Illuminate\Support\Str;

class SocialLoginAction
{
    protected Apps $app;

    /**
     * Construct function.
     */
    public function __construct(
        protected  SocialiteUser $socialUser,
        protected string $provider
    ) {
    }

    /**
     * Login a user and create if not exist.
     *
     * @param SocialiteUser $socialUser
     * @param string $provider
     * @return Users
     */
    public function execute(): Users
    {
        $source = Sources::where('title', $this->provider)->firstOrFail();
        $userLinkedSource = UserLinkedSources::where('source_users_id', $this->socialUser->id)->where('source_id', $source->id)->first();

        if (!$userLinkedSource) {
            $existedUser = Users::where('email', $this->socialUser->email)->first();

            if (!$existedUser) {
                $userData = [
                    'firstname' => $this->socialUser->name,
                    'email' => $this->socialUser->email,
                    'password' => Str::random(11),
                    'displayname' => $this->socialUser->nickname
                ];
                $userData = RegisterInput::fromArray($userData);

                $registeredUser = new RegisterUsersAction($userData);
                $existedUser = $registeredUser->execute();
            }

            $userLinkedSource = UserLinkedSources::createSocial($this->socialUser, $existedUser, $source);
        }

        return $userLinkedSource->user;
    }
}
