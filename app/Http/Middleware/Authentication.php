<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Traits\TokenTrait;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Models\Users;
use Lcobucci\JWT\Token;

class Authentication
{
    use TokenTrait;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (! empty($request->bearerToken())) {
            $token = $this->decodeToken($request->bearerToken());
        } else {
            throw new AuthorizationException('Missing Token');
        }

        if (! $this->validateJwtToken($token)) {
            throw new AuthorizationException('Invalid Token');
        }

        if ($token->isExpired(now())) {
            throw new AuthorizationException('Token Expired');
        }

        //  $user = $this->sessionUser($token, $request);

        /*   App::bind(Users::class, function () use ($user) {
              return $user;
          });

          App::alias(Users::class, 'userData'); */

        return $next($request);
    }

    /**
     * Get the real from the JWT Token.
     *
     * @param Micro $api
     * @param Config $config
     * @param RequestInterface $request
     *
     * @throws UnauthorizedException
     *
     * @return void
     *
     * @deprecated version 1.x no need anymore for this, user() will check the user
     *
     * @todo Set userdata on DI ??
     */
    protected function sessionUser(Token $token, Request $request)
    {
        $session = new Sessions();
        $userData = new Users();

        if (! empty($token->claims()->get('sessionId'))) {
            if (! $user = $userData->getByEmail($token->claims()->get('email'))) {
                throw new Exception('User not found');
            }

            return $session->check(
                $user,
                $token->claims()->get('sessionId'),
                (string) $request->ip(),
                app(Apps::class),
                1
            );
        } else {
            throw new Exception('User not found');
        }
    }
}
