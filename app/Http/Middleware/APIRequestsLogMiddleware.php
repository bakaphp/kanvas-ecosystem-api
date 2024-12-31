<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kanvas\Services\BatchLoggerService;

class APIRequestsLogMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $batchLogger = new BatchLoggerService();

        // Extract GraphQL query details
        $graphQuery = $request->input('query', '');
        preg_match('/\{\s*([\w_]+)/', $graphQuery, $matches);

        // Prepare request info
        $requestInfo = json_encode([
            'app_id' => 'App Id: ' . $request->header('X-Kanvas-App'),
            'method' => $request->method(),
            'type_request' => str_contains($graphQuery, 'mutation') ? 'mutation' : 'query',
            'resource' => $matches[1] ?? null,
            'status_code' => $response->getStatusCode(),
        ]);

        $batchLogger->log($requestInfo);

        return $response;
    }
}
