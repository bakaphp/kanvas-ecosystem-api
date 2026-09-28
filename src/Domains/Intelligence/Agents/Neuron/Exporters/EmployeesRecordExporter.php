<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Override;

class EmployeesRecordExporter implements RecordExporterInterface
{
    use ReadsExportFilters;

    #[Override]
    public function type(): string
    {
        return 'employees';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'optional department (partial name), status';
    }

    #[Override]
    public function headers(): array
    {
        return ['Name', 'Email', 'Position', 'Department', 'Status'];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $department = $this->filterString($filters, 'department');
        $status = $this->filterString($filters, 'status');

        $employees = Employee::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->when($department !== null, fn ($q) => $q->whereHas(
                'department',
                fn ($d) => $d->where('name', 'like', '%' . (string) $department . '%'),
            ))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with(['people', 'user', 'position', 'department'])
            ->orderBy('id')
            ->limit(RecordExporterRegistry::MAX_ROWS)
            ->get();

        return $employees->map(fn (Employee $employee): array => [
            $employee->people?->name ?? '',
            $employee->user?->email ?? '',
            $employee->position?->title ?? '',
            $employee->department?->name ?? '',
            $employee->status ?? '',
        ])->all();
    }
}
