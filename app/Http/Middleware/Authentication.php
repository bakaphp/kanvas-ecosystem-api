<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Traits\TokenTrait;
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
        if (!empty($request->bearerToken())) {
            $token = $this->getToken($request->bearerToken());
        } else {
            throw new Exception('Missing Token');
        }

        if (!$this->validateJwtToken($token)) {
            throw new Exception('Invalid Token');
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
     * @param Token $token
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

        if (!empty($token->claims()->get('sessionId'))) {
            if (!$user = $userData->getByEmail($token->claims()->get('email'))) {
                throw new Exception('User not found');
            }

            $ip = !defined('API_TESTS') ? $request->ip() : '127.0.0.1';
            return $session->check($user, $token->claims()->get('sessionId'), (string) $ip, 1);
        } else {
            throw new Exception('User not found');
        }
    }
}
