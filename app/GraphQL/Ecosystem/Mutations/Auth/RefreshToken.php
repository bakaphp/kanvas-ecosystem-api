<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Auth;

use Kanvas\Traits\TokenTrait;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;

class RefreshToken
{
    use TokenTrait;

    /**
     * resolve
     *
     * @param  mixed $rootValue
     * @param  array $req
     * @return void
     */
    public function resolve(mixed $rootValue, array $req)
    {
        try {
            $token = $this->getToken($req['refresh_token']);
            if ($token->isExpired(now())) {
                throw new AuthorizationException('Expired refresh token');
            }
            $user = UsersRepository::getByEmail($token->claims()->get('email'));
            return $user->createToken('kanvas-login')->toArray();
        } catch (\Throwable $e) {
            throw new Exception($e->getMessage());
        }
    }
}
