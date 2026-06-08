<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Models\Session;

class SessionRepository
{
    public static function getByUuidAndNamespaceFromCompanyApp(
        string $uuid,
        AppInterface $app,
        string $namespace = Lead::class,
        ?CompanyInterface $company = null,
    ): Session {
        $session = Session::query()
            ->where('uuid', $uuid)
            ->where('entity_namespace', $namespace)
            ->when($company, fn ($q) => $q->where('companies_id', $company->getId()))
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->first();

        if (! $session) {
            throw new ModelNotFoundException(
                "Session not found for uuid {$uuid} and namespace {$namespace} in company {$company?->getId()} and app {$app->getId()}"
            );
        }

        return $session;
    }
}
