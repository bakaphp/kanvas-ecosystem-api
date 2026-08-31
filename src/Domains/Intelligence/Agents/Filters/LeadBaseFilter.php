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
        $status = strtolower(trim((string) ($filters['status'] ?? ''))) ?: 'open';
        $query
            ->when($status === 'open', fn ($q) => $q->where(fn ($scope) => $scope->whereNull('status')->orWhere('status', '<', 2)))
            ->when($status === 'closed', fn ($q) => $q->where('status', '>=', 2));
        $criteria = ['status' => $status];

        if (($source = trim((string) ($filters['source'] ?? ''))) !== '') {
            $query->whereHas('source', fn ($q) => $q->where('name', 'like', "%{$source}%"));
            $criteria['source'] = $source;
        }
        if (($stage = trim((string) ($filters['stage'] ?? ''))) !== '') {
            $query->whereHas('stage', fn ($q) => $q->where('name', 'like', "%{$stage}%"));
            $criteria['stage'] = $stage;
        }
        if (($salesperson = trim((string) ($filters['salesperson'] ?? ''))) !== '') {
            $ownerIds = Users::query()
                ->where(fn ($owner) => $owner->where('firstname', 'like', "%{$salesperson}%")
                    ->orWhere('lastname', 'like', "%{$salesperson}%")
                    ->orWhere('email', 'like', "%{$salesperson}%"))
                ->pluck('id');
            $query->whereIn('leads_owner_id', $ownerIds);
            $criteria['salesperson'] = $salesperson;
        }
        if (($rooftop = trim((string) ($filters['rooftop'] ?? ''))) !== '') {
            $branchIds = CompaniesBranches::query()
                ->where('companies_id', $company->getId())
                ->where('name', 'like', "%{$rooftop}%")
                ->pluck('id');
            $query->whereIn('companies_branches_id', $branchIds);
            $criteria['rooftop'] = $rooftop;
        }
        if (($createdAfter = trim((string) ($filters['created_after'] ?? ''))) !== '') {
            $query->where('created_at', '>=', Carbon::parse($createdAfter)->startOfDay());
            $criteria['created_after'] = $createdAfter;
        }
        if (($createdBefore = trim((string) ($filters['created_before'] ?? ''))) !== '') {
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
}
