<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\KanvasModules\Enums\KanvasModuleEnum;
use Kanvas\KanvasModules\Models\CompanyKanvasModule;
use Kanvas\KanvasModules\Services\KanvasModuleSummaryProviderRegistry;
use Throwable;

class RefreshCompanyKanvasModulesSummaryAction
{
    public function __construct(
        protected readonly Companies $company,
        protected readonly Apps $app,
    ) {
    }

    /**
     * @return array{refreshed: int, module_ids: array<int, int>}
     */
    public function execute(): array
    {
        $rows = CompanyKanvasModule::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->where('is_active', 1)
            ->get();

        $refreshed = 0;
        $refreshedIds = [];

        foreach ($rows as $row) {
            $moduleEnum = KanvasModuleEnum::tryFrom($row->kanvas_modules_id);
            if ($moduleEnum === null) {
                continue;
            }

            $provider = KanvasModuleSummaryProviderRegistry::for($moduleEnum);
            if ($provider === null) {
                continue;
            }

            try {
                $row->summary = $provider->summary($this->company, $this->app);
                $row->saveQuietly();
                $refreshed++;
                $refreshedIds[] = $row->kanvas_modules_id;
            } catch (Throwable $e) {
                // One bad provider must not break the rest of the dashboard
                // refresh — log and move on.
                report($e);
            }
        }

        return [
            'refreshed' => $refreshed,
            'module_ids' => $refreshedIds,
        ];
    }
}
