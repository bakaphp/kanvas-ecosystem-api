<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use DDTrace\Tracer;
use DDTrace\GlobalTracer;
use Illuminate\Support\Facades\Log;
use Kanvas\Services\BatchLogger;

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

        $batchLogger = new BatchLogger();

        // Extract GraphQL query details
        $graphQuery = $request->input('query', '');
        preg_match('/\{\s*([\w_]+)/', $graphQuery, $matches);

        // Prepare request info
        $requestInfo = json_encode([
            'method' => $request->method(),
            'type_request' => str_contains($graphQuery, 'mutation') ? 'mutation' : 'query',
            'resource' => $matches[1] ?? null,
            'status_code' => $response->getStatusCode(),
        ]);

        // Log request
        $batchLogger->log($requestInfo);

        return $response;
    }
}
