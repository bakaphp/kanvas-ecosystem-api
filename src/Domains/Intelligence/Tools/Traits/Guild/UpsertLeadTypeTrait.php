<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeData;
use Kanvas\Guild\Leads\Models\LeadType;
use Throwable;

trait UpsertLeadTypeTrait
{
    protected function upsertLeadType(
        AppInterface $app,
        CompanyInterface $company,
        string $name,
        ?string $description = null,
    ): array {
        try {
            $existing = LeadType::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('name', $name)
                ->where('is_deleted', 0)
                ->first();

            if ($existing) {
                return [
                    'lead_type_id' => $existing->getId(),
                    'name' => $existing->name,
                    'action' => 'found',
                ];
            }

            $leadType = new CreateLeadTypeAction(
                new LeadTypeData(
                    apps: $app,
                    companies: $company,
                    name: $name,
                    description: $description ?? $name,
                    is_active: 1,
                ),
            )->execute();
        } catch (Throwable $e) {
            return ['error' => "Failed to upsert lead type: {$e->getMessage()}"];
        }

        return [
            'lead_type_id' => $leadType->getId(),
            'name' => $leadType->name,
            'action' => 'created',
        ];
    }
}
