<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Exporters;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Exporters\Traits\ReadsExportFilters;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExtractsPersonContacts;
use Override;

class PeopleRecordExporter implements RecordExporterInterface
{
    use ExtractsPersonContacts;
    use ReadsExportFilters;

    #[Override]
    public function type(): string
    {
        return 'people';
    }

    #[Override]
    public function filtersHint(): string
    {
        return 'optional organization, tag, people_type — all partial-name matches';
    }

    #[Override]
    public function headers(): array
    {
        return ['Name', 'Email', 'Phone', 'Organization', 'Person Type'];
    }

    #[Override]
    public function rows(Apps $app, Companies $company, array $filters): array
    {
        $organization = $this->filterString($filters, 'organization');
        $tag = $this->filterString($filters, 'tag');
        $peopleType = $this->filterString($filters, 'people_type');

        $people = People::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->when($organization !== null, fn ($q) => $q->whereHas(
                'organizations',
                fn ($o) => $o->where('name', 'like', '%' . $organization . '%'),
            ))
            ->when($tag !== null, fn ($q) => $q->whereHas(
                'tags',
                fn ($t) => $t->where('name', 'like', '%' . $tag . '%'),
            ))
            ->when($peopleType !== null, fn ($q) => $q->whereHas(
                'peopleType',
                fn ($p) => $p->where('name', 'like', '%' . $peopleType . '%'),
            ))
            ->with(['contacts', 'organizations', 'peopleType'])
            ->orderBy('id')
            ->limit(RecordExporterRegistry::MAX_ROWS)
            ->get(['id', 'firstname', 'lastname', 'name', 'people_types_id']);

        return $people->map(fn (People $person): array => [
            $person->getName(),
            $this->primaryEmail($person) ?? '',
            $this->primaryPhone($person) ?? '',
            $person->organizations->first()?->name ?? '',
            $person->peopleType?->name ?? '',
        ])->all();
    }
}
