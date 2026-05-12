<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Models\LeadSource;
use Throwable;

trait UpsertLeadSourceTrait
{
    protected function upsertLeadSource(
        AppInterface $app,
        CompanyInterface $company,
        string $name,
        ?string $description = null,
    ): array {
        try {
            $existing = LeadSource::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('name', $name)
                ->where('is_deleted', 0)
                ->first();

            if ($existing) {
                return [
                    'lead_source_id' => $existing->getId(),
                    'name' => $existing->name,
                    'action' => 'found',
                ];
            }

            $leadSource = new LeadSource();
            $leadSource->apps_id = $app->getId();
            $leadSource->companies_id = $company->getId();
            $leadSource->name = $name;
            $leadSource->description = $description ?? $name;
            $leadSource->is_active = 1;
            $leadSource->saveOrFail();
        } catch (Throwable $e) {
            return ['error' => "Failed to upsert lead source: {$e->getMessage()}"];
        }

        return [
            'lead_source_id' => $leadSource->getId(),
            'name' => $leadSource->name,
            'action' => 'created',
        ];
    }
}
