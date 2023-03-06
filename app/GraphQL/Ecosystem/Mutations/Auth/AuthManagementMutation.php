<?php

declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Auth;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Auth\Services\ForgotPassword as ForgotPasswordService;
use Kanvas\Auth\Traits\AuthTrait;
use Kanvas\Auth\Traits\TokenTrait;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;

class AuthManagementMutation
{
    use TokenTrait;
    use AuthTrait;

    /**
     * @param $rootValue
     * @param array $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     * @param \GraphQL\Type\Definition\ResolveInfo $resolveInfo
     *
     * @return array
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
     *
     * @param mixed $rootValue
     * @param array $request
     * @param GraphQLContext|null $context
     * @param ResolveInfo $resolveInfo
     *
     * @return void
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
     * @param $rootValue
     * @param array $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     * @param \GraphQL\Type\Definition\ResolveInfo $resolveInfo
     *
     * @return array
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
     * @param $rootValue
     * @param array $args
     * @param \Nuwave\Lighthouse\Support\Contracts\GraphQLContext|null $context
     * @param \GraphQL\Type\Definition\ResolveInfo $resolveInfo
     *
     * @return array
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
                    Password::min(8)
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
            'token' => $tokenResponse
        ];
    }

    /**
     * resolve
     *
     * @param  mixed $rootValue
     * @param  array $req
     * @return void
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
     *
     * @param  mixed $root
     * @param  array $req
     * @return array
     */
    public function switchCompanyBranch(mixed $root, array $req): bool
    {
        $action = new SwitchCompanyBranchAction(auth()->user(), $req['company_branch_id']);
        return $action->execute();
    }
}
