<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Providers\Summary;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\KanvasModules\Contracts\KanvasModuleSummaryProvider;
use Kanvas\Workflow\Rules\Models\Rule;
use Override;

class WorkflowModuleSummaryProvider implements KanvasModuleSummaryProvider
{
    #[Override]
    public function summary(Companies $company, Apps $app): array
    {
        $rules = Rule::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->count();

        return [
            'rules' => $rules,
        ];
    }
}
