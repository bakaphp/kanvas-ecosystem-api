<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;

final class CompanyKnowledgeQuery
{
    use ResolvesActingContext;

    /**
     * @return Collection<int, Filesystem>
     */
    public function documents(mixed $root, array $request): Collection
    {
        return new CompanyKnowledgeService()->documents($this->company());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function urls(mixed $root, array $request): array
    {
        return array_map(
            CompanyKnowledgeService::presentUrl(...),
            new CompanyKnowledgeService()->urls($this->company())
        );
    }

    private function company(): Companies
    {
        /** @var Companies $company */
        $company = $this->actingContext()->company;

        return $company;
    }
}
