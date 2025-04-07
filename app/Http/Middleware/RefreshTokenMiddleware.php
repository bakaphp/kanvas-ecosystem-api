<?php

namespace App\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kanvas\Auth\Traits\TokenTrait;

class RefreshTokenMiddleware
{
    use TokenTrait;

    public function handle(Request $request, \Closure $next, string ...$guards): mixed
    {
        if (empty($request->bearerToken())) {
            return $next($request);
        }

        $token = $this->decodeToken($request->bearerToken());

        if (! $this->validateJwtToken($token)) {
            return $this->buildErrorResponse(401, 'Invalid Token');
        }

        if ($token->isExpired(now())) {
            return $this->buildErrorResponse(401, 'Token Expired');
        }

        return $next($request);
    }

    protected function buildErrorResponse(int $status, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [
                [
                    'message' => $message,
                    'extensions' => [
                        'reason' => null, // You can populate this if needed
                    ],
                ],
            ],
        ], $status);
    }
}
