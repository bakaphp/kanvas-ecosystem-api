<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Override;

class OrganizationsRecordExporter implements RecordExporterInterface
{
    use ReadsExportFilters;

    #[Override]
    public function type(): string
    {
        return 'organizations';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'optional search — partial name match';
    }

    #[Override]
    public function headers(): array
    {
        return ['Name', 'Created At'];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $search = $this->filterString($filters, 'search');

        $orgs = Organization::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->when($search !== null, fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->orderByDesc('created_at')
            ->limit(RecordExporterRegistry::MAX_ROWS)
            ->get(['id', 'name', 'created_at']);

        return $orgs->map(fn (Organization $org): array => [
            $org->name,
            $org->created_at?->toDateString() ?? '',
        ])->all();
    }
}
