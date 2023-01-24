<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Auth;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Auth\Services\ForgotPassword as ForgotPasswordService;
use Kanvas\Auth\Traits\AuthTrait;
use Kanvas\Auth\Traits\TokenTrait;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ForgotPassword
{
    use AuthTrait;
    use TokenTrait;

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
    ) {
        $user = new ForgotPasswordService();

        $registeredUser = $user->forgot($request['data']['email']);
        $tokenResponse = $registeredUser->createToken('kanvas-login')->toArray();

        $request = request();

        return [
            'user' => $registeredUser,
            'token' => $tokenResponse
        ];
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
    ) {
        $user = new ForgotPasswordService();

        return $user->reset($request['data']['new_password'], $request['data']['hash_key']);
    }
}
