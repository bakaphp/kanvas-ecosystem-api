<?php
declare(strict_types=1);

namespace Kanvas\Auth;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\TokenGuard as AuthTokenGuard;
use Illuminate\Http\Request;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Traits\TokenTrait;
use Kanvas\Users\Models\Users;
use Lcobucci\JWT\Token;

class TokenGuard extends AuthTokenGuard
{
    use TokenTrait;

    /**
     * Get the currently authenticated user.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function user()
    {
        // If we've already retrieved the user for the current request we can just
        // return it back immediately. We do not want to fetch the user data on
        // every call to this method because that would be tremendously slow.
        if (!is_null($this->user)) {
            return $this->user;
        }

        $user = null;

        $requestToken = $this->getTokenForRequest();

        if (!empty($requestToken)) {
            $token = $this->getToken($requestToken);
            if ($token instanceof Token) {
                if (!$this->validateJwtToken($token)) {
                    throw new AuthorizationException('Invalid Token');
                }

                $user = $this->sessionUser($token, $this->request);
            }
        }

        return $this->user = $user;
    }

    /**
     * Get the real from the JWT Token.
     *
     * @param Micro $api
     * @param Config $config
     * @param Token $token
     * @param RequestInterface $request
     *
     * @throws UnauthorizedException
     *
     * @return Users
     */
    protected function sessionUser(Token $token, Request $request) : Users
    {
        $session = new Sessions();
        $userData = new Users();

        if (!empty($token->claims()->get('sessionId'))) {
            if (!$user = $userData->getByEmail($token->claims()->get('email'))) {
                throw new AuthorizationException('User not found');
            }

            $ip = !defined('API_TESTS') ? $request->ip() : '127.0.0.1';
            return $session->check(
                $user,
                $token->claims()->get('sessionId'),
                (string) $ip,
                1
            );
        } else {
            throw new AuthorizationException('User not found');
        }
    }
}
