<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Auth;

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
use Kanvas\Auth\Traits\AuthTrait;
use Kanvas\Auth\Traits\TokenTrait;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Repositories\UsersRepository;
use Laravel\Socialite\Facades\Socialite;
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
        $request = request();

        $user = $this->login(
            LoginInput::from([
                'email' => $email,
                'password' => $password,
                'ip' => $request->ip(),
            ])
        );

        return $user->createToken('kanvas-login')->toArray();
    }

    /**
     * Logout from the current JWT token
     */
    public function logout(mixed $rootValue, array $request): bool
    {
        $session = new Sessions();

        return $session->end(
            auth()->user(),
            app(Apps::class),
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

        $data = RegisterInput::fromArray($request['data']);
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

        $user = Socialite::driver($provider)->userFromToken($token);
        $socialLogin = new SocialLoginAction($user, $provider);

        $loggedUser = $socialLogin->execute();
        $tokenResponse = $loggedUser->createToken('kanvas-login')->toArray();

        return $tokenResponse;
    }
}
