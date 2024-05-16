<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Auth;

use Baka\Validations\PasswordValidation;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\Actions\SocialLoginAction;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Services\ForgotPassword as ForgotPasswordService;
use Kanvas\Auth\Socialite\SocialManager;
use Kanvas\Auth\Traits\AuthTrait;
use Kanvas\Auth\Traits\TokenTrait;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Enums\UserConfigEnum;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AuthManagementMutation
{
    use TokenTrait;
    use AuthTrait;

    /**
     * @param array $args
     *
     * @throws \Exception
     */
    public function loginMutation(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ): array {
        $email = $request['data']['email'];
        $password = $request['data']['password'];
        $deviceId = $request['data']['device_id'] ?? null;
        $request = request();

        $user = $this->login(
            LoginInput::from([
                'email' => $email,
                'password' => $password,
                'ip' => $request->ip(),
                'deviceId' => $deviceId
            ])
        );

        return $user->createToken(name: 'kanvas-login', deviceId: $deviceId)->toArray();
    }

    /**
     * Logout from the current JWT token
     */
    public function logout(mixed $rootValue, array $request): bool
    {
        $session = new Sessions();
        $app = app(Apps::class);
        $user = auth()->user();
        $userApp = $user->getAppProfile($app);

        //if the user has 2fa enabled and the 30 days validation is not enabled
        if (! $user->get(UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value) && $userApp->phone_verified_at) {
            $userApp->phone_verified_at = null;
            $userApp->save();
        }

        return $session->end(
            $user,
            $app,
            auth()->getRequestJwtToken()->claims()->get('sessionId')
        );
    }

    /**
     * Logout from all devices
     */
    public function logoutFromAllDevices(mixed $rootValue, array $request): bool
    {
        $session = new Sessions();

        return $session->end(
            auth()->user(),
            app(Apps::class)
        );
    }

    /**
     * @param array $args
     *
     * @throws \Exception
     */
    public function register(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ): array {
        $app = app(Apps::class);

        Validator::make(
            $request['data'],
            [
                'password' => [
                    'required',
                    'confirmed',
                    Password::min(8),
                ],
            ]
        )->validate();
        PasswordValidation::validateArray($request['data'], $app);

        $userRegistrationAssignToAppDefaultCompanyBranch = $app->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
        $branch = null;
        if ($userRegistrationAssignToAppDefaultCompanyBranch) {
            $branch = CompaniesBranches::getById($userRegistrationAssignToAppDefaultCompanyBranch);
        }

        $data = RegisterInput::fromArray($request['data'], $branch);
        $user = new RegisterUsersAction($data);
        $request = request();

        $registeredUser = $user->execute();
        $tokenResponse = $registeredUser->createToken('kanvas-login')->toArray();

        return [
            'user' => $registeredUser,
            'token' => $tokenResponse,
        ];
    }

    /**
     * resolve
     */
    public function refreshToken(mixed $rootValue, array $req): array
    {
        $token = $this->decodeToken($req['refresh_token']);
        if ($token->isExpired(now())) {
            throw new AuthorizationException('Expired refresh token');
        }
        $user = UsersRepository::getByEmail($token->claims()->get('email'));

        return $user->createToken('kanvas-login')->toArray();
    }

    /**
     * switchCompanyBranch
     */
    public function switchCompanyBranch(mixed $root, array $req): bool
    {
        $action = new SwitchCompanyBranchAction(auth()->user(), $req['company_branch_id']);

        return $action->execute();
    }

    /**
     * Login with social login
     */
    public function socialLogin(mixed $root, array $req): array
    {
        $data = $req['data'];
        $token = $data['token'];
        $provider = $data['provider'];
        $user = SocialManager::getDriver($provider)->getUserFromToken($token);
        $socialLogin = new SocialLoginAction($user, $provider);

        $loggedUser = $socialLogin->execute();
        $tokenResponse = $loggedUser->createToken(name: 'kanvas-login')->toArray();

        return $tokenResponse;
    }

    /**
     * @param array $args
     *
     * @throws \Exception
     */
    public function forgot(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ): bool {
        $user = new ForgotPasswordService();

        $registeredUser = $user->forgot($request['data']['email']);
        $tokenResponse = $registeredUser->createToken('kanvas-login')->toArray();

        $request = request();

        return true;
    }

    /**
     * Reset user password.
     */
    public function reset(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ): bool {
        $user = new ForgotPasswordService();

        $user->reset($request['data']['new_password'], $request['data']['hash_key']);

        return true;
    }
}
