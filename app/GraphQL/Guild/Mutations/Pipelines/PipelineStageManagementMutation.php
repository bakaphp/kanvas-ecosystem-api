<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Pipelines;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Pipelines\Actions\CreateStagePipelineAction;
use Kanvas\Guild\Pipelines\Actions\UpdateStagePipelineAction;
use Kanvas\Guild\Pipelines\DataTransferObject\Pipeline;
use Kanvas\Guild\Pipelines\DataTransferObject\PipelineStage;
use Kanvas\Guild\Pipelines\Models\Pipeline as ModelsPipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage as ModelsPipelineStage;

class PipelineStageManagementMutation
{
    /**
     * Create new pipeline
     */
    public function create(mixed $root, array $req): ModelsPipelineStage
    {
        $user = auth()->user();

        $pipeline = ModelsPipeline::getByIdFromCompany(
            (int) $req['input']['pipeline_id'],
            $user->getCurrentCompany()
        );

        $stageAction = new CreateStagePipelineAction(
            PipelineStage::viaRequest(
                $pipeline,
                $req['input']
            )
        );

        return $stageAction->execute();
    }

    public function update(mixed $root, array $req): ModelsPipelineStage
    {
        $user = auth()->user();
        $id = (int) $req['id'];

        $pipeline = ModelsPipeline::getByIdFromCompany(
            (int) $req['input']['pipeline_id'],
            $user->getCurrentCompany()
        );

        $pipelineStage = $pipeline->stages()->where('id', $id)->firstOrFail();

        $updatePipeline = new UpdateStagePipelineAction(
            $pipelineStage,
            PipelineStage::viaRequest(
                $pipeline,
                $req['input']
            )
        );

        return $updatePipeline->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $id = (int) $req['id'];

        $pipeline = ModelsPipeline::getByIdFromCompany(
            (int) $req['input']['pipeline_id'],
            $user->getCurrentCompany()
        );

        $pipelineStage = $pipeline->stages()->where('id', $id)->firstOrFail();

        //cant delete if its been used by a lead
        if ($pipelineStage->leads()->count() > 0) {
            throw new ValidationException('Can\'t Delete pipeline stage is being used by a lead');
        }

        return $pipelineStage->softDelete();
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function restore(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $id = (int) $req['id'];

        $pipeline = ModelsPipeline::getByIdFromCompany(
            (int) $req['input']['pipeline_id'],
            $user->getCurrentCompany()
        );

        return $pipeline->stages()
            ->where('id', $id)
            ->firstOrFail()
            ->restoreRecord();
    }
}
