<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Auth;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Kanvas\Auth\Traits\AuthTrait;
use Kanvas\Auth\Traits\TokenTrait;
use Kanvas\Users\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Register
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
    public function resolve(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ) {
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
}
