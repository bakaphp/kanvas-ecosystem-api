<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\KanvasModules\Enums\CompanyKanvasModuleStatusEnum;
use Kanvas\KanvasModules\Models\CompanyKanvasModule;
use Kanvas\KanvasModules\Models\KanvasModule;

class ProvisionCompanyKanvasModulesAction
{
    public function __construct(
        protected readonly Companies $company,
        protected readonly Apps $app,
    ) {
    }

    /**
     * @return array{provisioned: int, module_ids: array<int, int>}
     */
    public function execute(): array
    {
        $eligibleIds = DB::table('abilities_modules')
            ->where('apps_id', $this->app->getId())
            ->distinct()
            ->pluck('module_id')
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($eligibleIds === []) {
            return ['provisioned' => 0, 'module_ids' => []];
        }

        $modules = KanvasModule::whereIn('id', $eligibleIds)
            ->where('is_default', 1)
            ->where('is_deleted', 0)
            ->get(['id', 'is_internal']);

        $provisioned = 0;
        $provisionedIds = [];

        foreach ($modules as $module) {
            $status = $module->is_internal
                ? CompanyKanvasModuleStatusEnum::CONNECTED
                : CompanyKanvasModuleStatusEnum::NOT_CONNECTED;

            CompanyKanvasModule::updateOrCreate(
                [
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                    'kanvas_modules_id' => $module->id,
                ],
                [
                    'is_active' => true,
                    'is_deleted' => false,
                    'status' => $status->value,
                ],
            );
            $provisioned++;
            $provisionedIds[] = (int) $module->id;
        }

        return [
            'provisioned' => $provisioned,
            'module_ids' => $provisionedIds,
        ];
    }
}
