<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Bouncer;
use Kanvas\Apps\Models\Apps;
use Kanvas\ACL\Repositories\RolesRepository;

class RolesMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $app = app(Apps::class);
        $user = auth()->user();
        if ($user) {
            Bouncer::scope()->to(RolesRepository::getScope());
        }

        return $next($request);
    }
}
