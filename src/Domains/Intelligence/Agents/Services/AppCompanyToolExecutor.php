<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Support\Str;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CompanyConfigurationAgent;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * An explicit company per operation, never a switch of the user's company or chat tenant.
 * Only an app-scoped global agent and an identified app administrator can use this boundary.
 * Callbacks are backend code, never class names or methods chosen by the model.
 */
class AppCompanyToolExecutor
{
    /** @param Closure(Companies): array $operation */
    public function execute(
        Apps $app,
        Agent $agent,
        Users $requestingUser,
        string $companyUuid,
        Closure $operation,
    ): array {
        if ($agent->type?->handler !== CompanyConfigurationAgent::class
            || (int) $agent->apps_id !== (int) $app->getId()
            || (int) $agent->companies_id !== 0
            || (bool) $agent->is_deleted
            || (bool) $requestingUser->is_deleted
            || (int) $requestingUser->getId() === (int) $agent->user_id) {
            throw new AuthorizationException('Only the Company Configuration Administrator type at app scope can select a company, with an identified human caller.');
        }

        // The operation runs inside the app scope on purpose: the inner tool performs its own admin guard.
        return $this->withinAppScope($app, function () use ($app, $requestingUser, $companyUuid, $operation): array {
            // A company administrator is not automatically an administrator of the whole app.
            if (! $requestingUser->isAdmin()) {
                throw new AuthorizationException('Only an administrator of this app can operate on another company.');
            }

            return $operation($this->resolveCompany($app, trim($companyUuid)));
        });
    }

    public function canAdministerApp(Apps $app, ?Users $user): bool
    {
        if ($user === null || (bool) $user->is_deleted) {
            return false;
        }

        return $this->withinAppScope($app, fn (): bool => $user->isAdmin());
    }

    /**
     * Bouncer filters every role query by the process-current scope, which in a long-lived worker
     * belongs to whatever ran before; pin the app's global scope and always restore the previous one.
     */
    private function withinAppScope(Apps $app, Closure $operation): mixed
    {
        $previousScope = Bouncer::scope()->get();

        try {
            Bouncer::scope()->to(RolesEnums::getScope($app, global: true));

            return $operation();
        } finally {
            Bouncer::scope()->to($previousScope);
        }
    }

    protected function resolveCompany(Apps $app, string $uuid): Companies
    {
        if (! Str::isUuid($uuid)) {
            throw new AuthorizationException('No authorized company matches that UUID in this app.');
        }

        $company = Companies::query()
            ->where('uuid', $uuid)
            ->notDeleted()
            ->where('id', '>', 0)
            ->whereIn('id', UserCompanyApps::query()
                ->select('companies_id')
                ->fromApp($app)
                ->notDeleted())
            ->first();

        if ($company === null) {
            // Same response for a nonexistent UUID and a company belonging to another app.
            throw new AuthorizationException('No authorized company matches that UUID in this app.');
        }

        return $company;
    }
}
