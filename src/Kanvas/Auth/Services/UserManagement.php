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
        // dd(app(Apps::class));

        return $this->user;
    }

    /**
     * Login a user and create if not exist.
     *
     * @param SocialiteUser $socialUser
     * @param string $provider
     * @return Users
     */
    public static function socialLogin(SocialiteUser $socialUser, string $provider): Users
    {
        $source = Sources::where('title', $provider)->firstOrFail();
        $userLinkedSource = UserLinkedSources::where('source_users_id',$socialUser->id)->where('source_id',$source->id)->first();

        if(!$userLinkedSource) {
            $existedUser = Users::getByEmail($socialUser->email);

            if(!$existedUser) {
                $userData = [
                    'firstname' => $socialUser->name,
                    'email' => $socialUser->email,
                    'password' => Str::random(11),
                    'displayname' => $socialUser->nickname
                ];
                $userData = RegisterInput::fromArray($userData);
    
                $registeredUser = new RegisterUsersAction($userData);
                $existedUser = $registeredUser->execute();
            }

            $userLinkedSource = UserLinkedSources::createSocial($socialUser, $existedUser, $source);
        }

        return $userLinkedSource->user;
    }
}
