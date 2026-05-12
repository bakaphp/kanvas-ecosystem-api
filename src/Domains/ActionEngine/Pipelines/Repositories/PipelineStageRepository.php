<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Repositories;

use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\ActionEngine\Tasks\Models\TaskListItem;
use Kanvas\Exceptions\ValidationException;

class PipelineStageRepository
{
    /**
     * Find a pipeline stage by slug, falling back to the pipeline's first stage
     * (ordered by weight) when no slug match exists. Returns a stage that
     * belongs to the given pipeline — never a cross-tenant hardcoded id.
     */
    public static function getByPipelineAndSlug(Pipeline $pipeline, string $slug): PipelineStage
    {
        $stage = $pipeline->stages()->where('slug', $slug)->first()
            ?? $pipeline->stages()->orderBy('weight')->first();

        if (! $stage) {
            throw new ValidationException(
                "Cannot resolve pipeline stage: pipeline {$pipeline->getId()} has no stages."
            );
        }

        return $stage;
    }

    /**
     * Resolve the pipeline stage for a TaskListItem matching the given slug,
     * falling back to the first stage of its companyAction's pipeline.
     * Throws when the companyAction has no pipeline or the pipeline has no
     * stages — both are config errors that should surface loudly.
     */
    public static function getForTaskListItem(TaskListItem $taskListItem, string $slug): PipelineStage
    {
        $pipeline = $taskListItem->companyAction->pipeline;

        if (! $pipeline) {
            throw new ValidationException(
                "Cannot resolve pipeline stage: companyAction {$taskListItem->companyAction->getId()} has no pipeline."
            );
        }

        return self::getByPipelineAndSlug($pipeline, $slug);
    }
}
