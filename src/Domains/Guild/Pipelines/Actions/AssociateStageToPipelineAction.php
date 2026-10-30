<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Actions;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Deals\Models\Deal;
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
        $existingStages = $this->pipeline->stages()->get();
        $matches = $this->matchExistingStages($existingStages);
        $keepStageIds = [];

        foreach ($this->stages as $index => $stage) {
            $attributes = [
                'name' => $stage['name'],
                'rotting_days' => $stage['rotting_days'],
                'weight' => ! empty($stage['weight']) ? $stage['weight'] : $index,
                'pipelines_id' => $this->pipeline->id,
            ];

            if (array_key_exists('config', $stage)) {
                $attributes['config'] = $stage['config'];
            }

            $existingStage = $matches[$index] ?? $this->findSoftDeletedStageByName($stage['name']);

            if ($existingStage !== null) {
                $existingStage->update($attributes + ['is_deleted' => 0]);
                $keepStageIds[] = $existingStage->id;

                continue;
            }

            $newStage = PipelineStage::create($attributes + [
                'has_rotting_days' => 0,
                'config' => $stage['config'] ?? null,
            ]);
            $keepStageIds[] = $newStage->id;
        }

        $stagesToRemove = $existingStages->reject(fn ($s) => in_array($s->id, $keepStageIds, true));

        if ($stagesToRemove->isEmpty()) {
            return;
        }

        $stageIds = $stagesToRemove->pluck('id')->all();

        if ($this->hasActiveRecords(Lead::class, $stageIds) || $this->hasActiveRecords(Deal::class, $stageIds)) {
            throw new ValidationException(
                'Cannot remove pipeline stages that have active leads or deals. Move them to another stage first.'
            );
        }

        foreach ($stagesToRemove as $stage) {
            $stage->softDelete();
        }
    }

    /**
     * Pair each input entry with an existing stage: explicit `stages_id` first, then exact name.
     *
     * When the client sends no `stages_id` at all, a second pass pairs the still-unmatched entries
     * with the still-unmatched stages in order. Doing it after the name pass (instead of per-entry
     * positional fallback) is what keeps a renamed stage on its own id when it was also reordered in
     * the same save; otherwise its leads silently end up labelled with a different stage's name.
     *
     * @param Collection<int, PipelineStage> $existingStages
     * @return array<int, PipelineStage|null>
     */
    private function matchExistingStages(Collection $existingStages): array
    {
        $byId = $existingStages->keyBy('id');
        $byName = $existingStages->keyBy('name');
        $usedIds = [];
        $matches = [];

        foreach ($this->stages as $index => $stage) {
            $stageId = ! empty($stage['stages_id']) ? (int) $stage['stages_id'] : null;
            $candidate = $stageId !== null && $byId->has($stageId)
                ? $byId->get($stageId)
                : $byName->get($stage['name']);

            if ($candidate !== null && in_array($candidate->id, $usedIds, true)) {
                $candidate = null;
            }

            if ($candidate !== null) {
                $usedIds[] = $candidate->id;
            }

            $matches[$index] = $candidate;
        }

        $anyHasExplicitId = collect($this->stages)->contains(fn ($s) => ! empty($s['stages_id']));

        if ($anyHasExplicitId) {
            return $matches;
        }

        $unmatchedExisting = $existingStages
            ->reject(fn ($s) => in_array($s->id, $usedIds, true))
            ->values();
        $cursor = 0;

        foreach ($matches as $index => $match) {
            if ($match !== null || ! isset($unmatchedExisting[$cursor])) {
                continue;
            }

            $matches[$index] = $unmatchedExisting[$cursor++];
        }

        return $matches;
    }

    private function findSoftDeletedStageByName(string $name): ?PipelineStage
    {
        return PipelineStage::where('pipelines_id', $this->pipeline->id)
            ->where('name', $name)
            ->where('is_deleted', 1)
            ->first();
    }

    /**
     * @param class-string<Lead|Deal> $model
     * @param int[] $stageIds
     */
    private function hasActiveRecords(string $model, array $stageIds): bool
    {
        return $model::whereIn('pipeline_stage_id', $stageIds)
            ->where('apps_id', $this->pipeline->apps_id)
            ->where('companies_id', $this->pipeline->companies_id)
            ->where('is_deleted', 0)
            ->exists();
    }
}
