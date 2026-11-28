<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\Audiences;

use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Notifications\Mailings\Contracts\AudienceResolver;

class AllAppUsersAudience implements AudienceResolver
{
    public function resolve(Apps $app, ?Companies $company, array $criteria): array
    {
        $query = DB::table('users_associated_apps')
            ->join('users', 'users_associated_apps.users_id', '=', 'users.id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
            ->where('users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('users.is_deleted', StateEnums::NO->getValue());

        $createdAfter = Str::trimToNull($criteria['created_after'] ?? null);
        $createdBefore = Str::trimToNull($criteria['created_before'] ?? null);

        if ($createdAfter !== null) {
            $query->where('users.created_at', '>=', $createdAfter);
        }

        if ($createdBefore !== null) {
            $query->where('users.created_at', '<=', $createdBefore);
        }

        return $query
            ->orderBy('users_associated_apps.users_id')
            ->distinct()
            ->pluck('users_associated_apps.users_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
