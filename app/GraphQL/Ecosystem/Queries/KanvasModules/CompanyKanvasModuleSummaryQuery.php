<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\KanvasModules;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\KanvasModules\Enums\CompanyKanvasModuleStatusEnum;
use Kanvas\KanvasModules\Models\CompanyKanvasModule;
use Kanvas\Users\Models\Users;

class CompanyKanvasModuleSummaryQuery
{
    /**
     * @return array{connected: int, partial: int, not_connected: int, total: int}
     */
    public function summary(mixed $rootValue, array $args): array
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        /** @var array<string, int> $counts */
        $counts = CompanyKanvasModule::query()
            ->fromCompany($company)
            ->fromApp($app)
            ->notDeleted()
            ->where('is_active', 1)
            ->whereHas(
                'module',
                fn (Builder $q): Builder => $q->where('is_internal', 0)->where('is_deleted', 0),
            )
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $connected = $counts[CompanyKanvasModuleStatusEnum::CONNECTED->value] ?? 0;
        $partial = $counts[CompanyKanvasModuleStatusEnum::PARTIAL->value] ?? 0;
        $notConnected = $counts[CompanyKanvasModuleStatusEnum::NOT_CONNECTED->value] ?? 0;

        return [
            'connected' => $connected,
            'partial' => $partial,
            'not_connected' => $notConnected,
            'total' => $connected + $partial + $notConnected,
        ];
    }
}
