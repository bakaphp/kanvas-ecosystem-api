<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\Status as WorkflowStatus;
use Kanvas\Workflow\Models\Integrations;

trait IntegrationEntityTrait
{
    public function integrationsHistory(): HasMany
    {
        return $this->hasMany(
            $this::class,
            'entity_id'
        )->where('entity_namespace', $this::class);
    }

    public function getEntityIntegrationsSummary(): Collection
    {
        $entity = $this;
        $integrations = Integrations::where(function ($query) use ($entity) {
            $query->where('apps_id', $entity->apps_id)
                ->orWhere('apps_id', 0);
        })->get();

        $integrationsEntities = $integrations->map(function ($integration) use ($entity) {
            $query = $integration->integrationsHistory()
                                    ->where('entity_namespace', $entity::class)
                                    ->where('entity_id', $this->getId());
            if(!$query->exists()) {
                $status = WorkflowStatus::getDefaultStatusByName(StatusEnum::OFFLINE->value);
            }

            $history = $query->latest()->first();

            return [
                'id' => $integration->getId(),
                'name' => $integration->name,
                'status' => $status ?? $history->status,
                'created_at'=> $history->created_at ?? null,
                'updated_at'=> $history->updated_at ?? null
            ];

        });
        return $integrationsEntities;
    }
}
