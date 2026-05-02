<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitGraphQLRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = (int) ($request->server('CONTENT_LENGTH') ?? 0);

        if ($contentLength <= 0) {
            return $next($request);
        }

        $contentType = strtolower((string) $request->header('Content-Type', ''));

        if (str_contains($contentType, 'multipart/form-data')) {
            $maxMultipartBytes = (int) config('lighthouse.request_size_limits.multipart_bytes', 25 * 1024 * 1024);
            if ($contentLength > $maxMultipartBytes) {
                return $this->payloadTooLarge('GraphQL multipart payload too large.');
            }

            return $next($request);
        }

        $maxJsonBytes = (int) config('lighthouse.request_size_limits.json_body_bytes', 512 * 1024);
        if ($contentLength > $maxJsonBytes) {
            return $this->payloadTooLarge('GraphQL request payload too large.');
        }

        return $next($request);
    }

    private function payloadTooLarge(string $message): JsonResponse
    {
        return response()->json(
            ['message' => $message],
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE
        );
    }
}
