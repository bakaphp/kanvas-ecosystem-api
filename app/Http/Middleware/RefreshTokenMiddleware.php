<?php

namespace App\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kanvas\Auth\Traits\TokenTrait;
use Lcobucci\JWT\Exception as JwtException;

class RefreshTokenMiddleware
{
    use TokenTrait;

    public function handle(Request $request, \Closure $next, string ...$guards): mixed
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken === null || empty($bearerToken)) {
            return $next($request);
        }

        try {
            $token = $this->decodeToken($bearerToken);
        } catch (JwtException $e) {
            Log::warning('Malformed JWT received in RefreshTokenMiddleware', [
                'message' => $e->getMessage(),
            ]);

            return $this->buildErrorResponse(401, 'Invalid Token');
        }

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
