<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Password;
use Baka\Users\Contracts\UserAppInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Laravel\Socialite\Facades\Socialite;
use Lcobucci\JWT\Token;

class AuthenticationService
{
    public function __construct(
        protected AppInterface $app
    ) {
    }

    /**
     * User login.
     */
    public function login(
        LoginInput $loginInput
    ): UserInterface {
        $app = $this->app;

        $displayNameLogin = $app->get(AppEnums::DISPLAYNAME_LOGIN->getValue());
        $email = $loginInput->getEmail();

        $userAssociatedAppQuery = UsersAssociatedApps::notDeleted()
            ->where('email', $email)
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('apps_id', $app->getId());

        if ($displayNameLogin) {
            $userAssociatedAppQuery->orWhere('displayname', $email);
        }

        $userAssociatedApp = $userAssociatedAppQuery->first();

        $user = $userAssociatedApp?->user;

        if (! $user) {
            throw new AuthenticationException('Invalid email or password.');
        }

        try {
            /**
             * until v3 (legacy) is deprecated we have to check or create the user profile the first time
             * @todo remove in v2
             */
            $authentically = $user->getAppProfile($app);
        } catch (ModelNotFoundException $e) {
            //user doesn't have a profile yet , verify if we need to create it
            try {
                UsersRepository::belongsToThisApp($user, $app);
            } catch (ModelNotFoundException $e) {
                throw new AuthenticationException('Invalid email or password.');
            }
            $userRegisterInApp = new RegisterUsersAppAction($user);
            $authentically = $userRegisterInApp->execute($user->password);
        }
        $this->loginAttemptsValidation($authentically);

        //password verification
        if (Hash::check($loginInput->getPassword(), $authentically->password) && $authentically->isActive()) {
            Password::rehash($loginInput->getPassword(), $authentically);
            $this->resetLoginTries($authentically);

            $company = $user->getCurrentCompany();
            if (! $company->isActive()) {
                $authMessage = $this->app->get(AppSettingsEnums::INACTIVE_COMPANY_ACCOUNT_ERROR_MESSAGE->getValue()) ?? 'Company is not active, please contact support.';

                throw new AuthenticationException($authMessage);
            }

            $user->fireWorkflow(
                WorkflowEnum::USER_LOGIN->value,
                true,
                ['company' => $company]
            );

            return $user;
        } elseif (! $authentically->isActive()) {
            $authMessage = $this->app->get(AppSettingsEnums::INACTIVE_ACCOUNT_ERROR_MESSAGE->getValue()) ?? 'User is not active, please contact support.';

            throw new AuthenticationException($authMessage);
        } elseif ($authentically->isBanned()) {
            throw new AuthenticationException('User has been banned, please contact support.');
        } else {
            $this->updateLoginTries($authentically);

            throw new AuthenticationException('Invalid email or password.');
        }
    }

    /**
     * Check the user login attempt to the app.
     *
     * @throws Exception
     */
    protected function loginAttemptsValidation(UserAppInterface $user): bool
    {
        $loginResetTime = $this->app->get('login_reset_time') ?? config('auth.login_reset_time');
        $maxLoginAttempts = $this->app->get('max_login_attempts') ?? config('auth.max_login_attempts');

        $currentTime = time();
        $timeThreshold = $currentTime - ($loginResetTime * 60);

        // Reset login attempts if the last attempt was earlier than the reset threshold
        if ($user->user_last_login_try && $user->user_last_login_try < $timeThreshold) {
            $user->user_login_tries = 0;
            $user->user_last_login_try = 0;
            $user->updateOrFail();
        }

        // Block login if maximum attempts are exceeded within the reset time period
        if ($user->user_last_login_try && $user->user_last_login_try >= $timeThreshold
            && $user->user_login_tries >= $maxLoginAttempts) {
            throw new AuthenticationException(
                sprintf(
                    'Your account has been locked due to %d failed login attempts. Please wait %d minutes to try again.',
                    $maxLoginAttempts,
                    $loginResetTime
                )
            );
        }

        return true;
    }

    /**
     * Reset login tries.
     */
    protected function resetLoginTries(UserAppInterface $user): bool
    {
        $user->lastvisit = date('Y-m-d H:i:s');
        $user->user_login_tries = 0;
        $user->user_last_login_try = 0;

        return $user->updateOrFail();
    }

    /**
     * Update login tries for the given user.
     */
    protected function updateLoginTries(UserAppInterface $user): bool
    {
        if ($user->users_id !== StatusEnums::ANONYMOUS->getValue()) {
            $user->user_login_tries += 1;
            $user->user_last_login_try = time();

            return $user->updateOrFail();
        }

        return false;
    }

    /**
     * clean user session
     */
    public function logout(Users $user, Token $token): bool
    {
        $sessionId = $token->claims()->get('sessionId') ?? null;

        $session = new Sessions();
        $session->end($user, app(Apps::class), $sessionId);

        $user->fireWorkflow(
            WorkflowEnum::USER_LOGOUT->value,
            true,
            ['company' => $user->getCurrentCompany()]
        );

        return true;
    }

    public static function getSocialite(Apps $app, string $provider)
    {
        $config = $app->get($provider . '_socialite');
        config(['services.' . $provider => $config]);

        return Socialite::driver($provider);
    }

    public static function getAppDefaultAssignCompanyBranch(Apps $app): ?CompaniesBranches
    {
        $userRegistrationAssignToAppDefaultCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
        $branch = null;

        try {
            if ($userRegistrationAssignToAppDefaultCompanyBranch) {
                $branch = CompaniesBranches::getById($userRegistrationAssignToAppDefaultCompanyBranch);
            }
        } catch (EloquentModelNotFoundException $e) {
        }

        return $branch;
    }

    public static function logoutFromAllDevices(Users $user, Apps $app): bool
    {
        $session = new Sessions();

        return $session->end(
            $user,
            $app
        );
    }
}
