<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Models\Users;

class LeadBaseFilter
{
    /** @return array<string, mixed> */
    public function apply(Builder $query, Companies $company, array $filters): array
    {
        $status = strtolower($this->text($filters, 'status')) ?: 'open';
        $query
            ->when($status === 'open', fn ($q) => $q->where(fn ($scope) => $scope->whereNull('status')->orWhere('status', '<', 2)))
            ->when($status === 'closed', fn ($q) => $q->where('status', '>=', 2));
        $criteria = ['status' => $status];

        if (($source = $this->text($filters, 'source')) !== '') {
            $query->whereHas('source', fn ($q) => $q->where('name', 'like', "%{$source}%"));
            $criteria['source'] = $source;
        }
        if (($stage = $this->text($filters, 'stage')) !== '') {
            $query->whereHas('stage', fn ($q) => $q->where('name', 'like', "%{$stage}%"));
            $criteria['stage'] = $stage;
        }
        if (($salesperson = $this->text($filters, 'salesperson')) !== '') {
            // Owners live on the ecosystem connection; a whereHas would join across databases.
            $ownerIds = Users::query()
                ->where(fn ($owner) => $owner->where('firstname', 'like', "%{$salesperson}%")
                    ->orWhere('lastname', 'like', "%{$salesperson}%")
                    ->orWhere('email', 'like', "%{$salesperson}%"))
                ->pluck('id');
            $query->whereIn('leads_owner_id', $ownerIds);
            $criteria['salesperson'] = $salesperson;
        }
        if (($rooftop = $this->text($filters, 'rooftop')) !== '') {
            $branchIds = CompaniesBranches::query()
                ->where('companies_id', $company->getId())
                ->where('name', 'like', "%{$rooftop}%")
                ->pluck('id');
            $query->whereIn('companies_branches_id', $branchIds);
            $criteria['rooftop'] = $rooftop;
        }
        if (($createdAfter = $this->text($filters, 'created_after')) !== '') {
            $query->where('created_at', '>=', Carbon::parse($createdAfter)->startOfDay());
            $criteria['created_after'] = $createdAfter;
        }
        if (($createdBefore = $this->text($filters, 'created_before')) !== '') {
            $query->where('created_at', '<=', Carbon::parse($createdBefore)->endOfDay());
            $criteria['created_before'] = $createdBefore;
        }
        $staleDays = $filters['no_update_since_days'] ?? null;
        if (is_int($staleDays) && $staleDays > 0) {
            $query->where('updated_at', '<=', Carbon::now()->subDays($staleDays));
            $criteria['no_update_since_days'] = $staleDays;
        }

        return $criteria;
    }

    private function text(array $filters, string $key): string
    {
        return trim((string) ($filters[$key] ?? ''));
    }
}
