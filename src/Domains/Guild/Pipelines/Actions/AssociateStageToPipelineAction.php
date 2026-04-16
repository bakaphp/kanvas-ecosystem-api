<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline as ModelsPipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;

class AssociateStageToPipelineAction
{
    public function __construct(
        public ModelsPipeline $pipeline,
        public array $stages
    ) {
    }

    public function execute(): void
    {
        $existingStages = $this->pipeline->stages()->orderBy('id')->get();
        $existingStagesById = $existingStages->keyBy('id');
        $existingStagesByName = $existingStages->keyBy('name');
        $usedExistingIds = [];
        $keepStageIds = [];

        // Only use position fallback when no stage sends stages_id (frontend behavior)
        $anyHasExplicitId = collect($this->stages)->contains(
            fn ($s) => ! empty($s['stages_id'])
        );

        foreach ($this->stages as $index => $stage) {
            $stageId = ! empty($stage['stages_id']) ? (int) $stage['stages_id'] : null;
            $attributes = [
                'name' => $stage['name'],
                'rotting_days' => $stage['rotting_days'],
                'weight' => ! empty($stage['weight']) ? $stage['weight'] : $index,
                'has_rotting_days' => 0,
                'config' => $stage['config'] ?? null,
                'pipelines_id' => $this->pipeline->id,
            ];

            // Find existing stage: by explicit ID, then by name
            $existingStage = null;
            if ($stageId !== null && $existingStagesById->has($stageId)) {
                $existingStage = $existingStagesById->get($stageId);
            } elseif ($existingStagesByName->has($stage['name'])) {
                $candidate = $existingStagesByName->get($stage['name']);
                if (! in_array($candidate->id, $usedExistingIds)) {
                    $existingStage = $candidate;
                }
            }

            // Position fallback only when frontend sends no stages_id at all
            if ($existingStage === null && ! $anyHasExplicitId && isset($existingStages[$index])) {
                $candidate = $existingStages[$index];
                if (! in_array($candidate->id, $usedExistingIds)) {
                    $existingStage = $candidate;
                }
            }

            if ($existingStage !== null) {
                $usedExistingIds[] = $existingStage->id;
                $existingStage->update($attributes);
                $keepStageIds[] = $existingStage->id;
            } else {
                $newStage = PipelineStage::create($attributes);
                $keepStageIds[] = $newStage->id;
            }
        }

        // Remove stages that are no longer in the input
        $stageIdsToRemove = ! empty($keepStageIds)
            ? $this->pipeline->stages()->whereNotIn('id', $keepStageIds)->pluck('id')
            : $this->pipeline->stages()->pluck('id');

        if ($stageIdsToRemove->isNotEmpty()) {
            $leadsInRemovedStages = Lead::whereIn('pipeline_stage_id', $stageIdsToRemove)
                ->where('apps_id', $this->pipeline->apps_id)
                ->where('companies_id', $this->pipeline->companies_id)
                ->where('is_deleted', 0)
                ->exists();

            if ($leadsInRemovedStages) {
                throw new ValidationException(
                    'Cannot remove pipeline stages that have active leads. Move leads to another stage first.'
                );
            }

            PipelineStage::whereIn('id', $stageIdsToRemove)->delete();
        }
    }
}
