<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Auth;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Auth\Traits\AuthTrait;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Login
{
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
    public function resolve(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ) {
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
}
