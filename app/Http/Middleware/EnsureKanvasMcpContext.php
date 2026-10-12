<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ModelNotFoundException;
use Symfony\Component\HttpFoundation\Response;

/**
 * KanvasAppKeyMiddleware resolves X-Kanvas-App / X-Kanvas-Key / X-Kanvas-Location for every request
 * but treats all three as optional, falling back to the default app and an anonymous user. An MCP
 * client has no login step to fall back on, so here the three are mandatory and must have resolved.
 */
class EnsureKanvasMcpContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $required = [
            AppEnums::KANVAS_APP_HEADER,
            AppEnums::KANVAS_APP_KEY_HEADER,
            AppEnums::KANVAS_APP_BRANCH_HEADER,
        ];

        foreach ($required as $header) {
            if (empty($request->header($header->getValue()))) {
                return $this->unauthorized('Missing required header: ' . $header->getValue());
            }
        }

        if (! app()->bound(AppKey::class) || auth()->user() === null) {
            return $this->unauthorized('Invalid or expired ' . AppEnums::KANVAS_APP_KEY_HEADER->getValue());
        }

        if (! app()->bound(CompaniesBranches::class)) {
            return $this->unauthorized('Invalid ' . AppEnums::KANVAS_APP_BRANCH_HEADER->getValue());
        }

        // The branch is looked up by uuid alone, so without this a valid key for one app could act
        // on a company that only exists in another.
        try {
            CompaniesRepository::hasAccessToThisApp(app(CompaniesBranches::class)->company, app(Apps::class));
        } catch (ModelNotFoundException) {
            return $this->unauthorized(
                AppEnums::KANVAS_APP_BRANCH_HEADER->getValue() . ' does not belong to this app'
            );
        }

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
