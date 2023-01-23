<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Auth;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Auth\Actions\ForgotPassword as ForgotPasswordAction;
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
        $user = new ForgotPasswordAction();

        $registeredUser = $user->forgot($request['data']);
        $tokenResponse = $registeredUser->createToken('kanvas-login')->toArray();

        $request = request();

        return [
            'user' => $registeredUser,
            'token' => $tokenResponse
        ];
    }

    /**
     * Reset user password
     *
     * @param mixed $rootValue
     * @param array $request
     * @param GraphQLContext|null $context
     * @param ResolveInfo $resolveInfo
     * @return void
     */
    public function reset(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ) {
        $user = new ForgotPasswordAction();

        return $user->reset($request['data']);
    }
}
