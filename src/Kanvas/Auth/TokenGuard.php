<?php

declare(strict_types=1);

namespace Kanvas\Auth;

use Illuminate\Auth\TokenGuard as AuthTokenGuard;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Traits\TokenTrait;
use Kanvas\Users\Models\Users;
use Lcobucci\JWT\Token;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

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
        if (! is_null($this->user)) {
            return $this->user;
        }
        $user = null;

        $requestToken = $this->getTokenForRequest();

        if (! empty($requestToken)) {
            $token = $this->getRequestJwtToken();
            $user = $this->sessionUser($token, $this->request);
        }

        return $this->user = $user;
    }

    public function getRequestJwtToken(): Token
    {
        $requestToken = $this->getTokenForRequest();

        if (! empty($requestToken)) {
            $token = $this->getToken($requestToken);
            if ($token instanceof Token) {
                if (! $this->validateJwtToken($token)) {
                    throw new AuthorizationException('Invalid Token');
                }

                if ($token->isExpired(now())) {
                    throw new AuthorizationException('Token Expired');
                }

                return $token;
            }
        }

        throw new AuthorizationException('No Token Provided');
    }

    /**
     * Get the real from the JWT Token.
     *
     * @throws AuthorizationException
     */
    protected function sessionUser(Token $token, Request $request): Users
    {
        $session = new Sessions();
        $userData = new Users();
        $app = app(Apps::class);

        if (! empty($token->claims()->get('sessionId'))) {
            $userSession = $session->getById($token->claims()->get('sessionId'), $app);
            $tokenDeviceId = $token->claims()->get('deviceId');

            if (! $user = $userSession->user()->first()) {
                throw new AuthorizationException('Session User not found');
            }

            $sessionUser = $session->check(
                $user,
                $token->claims()->get('sessionId'),
                (string)  $request->ip(),
                app(Apps::class),
                1
            );

            $sessionUser->setCurrentDeviceId($tokenDeviceId);

            return $sessionUser;
        } else {
            throw new AuthorizationException('Session User not found');
        }
    }

    public function loginUsingId(mixed $id, bool $remember = false)
    {
        $user = Users::getById((int) $id);
        $this->setUser($user);

        return $user;
    }
}
