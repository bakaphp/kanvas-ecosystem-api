<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use DDTrace\Tracer;
use DDTrace\GlobalTracer;
use Illuminate\Support\Facades\Log;

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

        $pattern = '/\{\s*([\w_]+)/';
        $graphQuery = $request->str('query')->value();
        preg_match_all($pattern, $graphQuery, $matches);
        
        $requestInfo = json_encode([
            'method' => $request->method(),
            'type_request' => str_contains($graphQuery, 'mutation') ? 'mutation' : 'query',
            'resource' => $matches[1][0],
            'status_code' => $response->getStatusCode(),
        ]);

        Log::channel('api_requests')->info($requestInfo);

        return $response;
    }
}
