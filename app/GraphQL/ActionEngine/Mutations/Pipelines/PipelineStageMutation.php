<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Pipelines;

use Kanvas\ActionEngine\Pipelines\Actions\CreatePipelineStageAction;
use Kanvas\ActionEngine\Pipelines\Actions\UpdatePipelineStageAction;
use Kanvas\ActionEngine\Pipelines\DataTransferObject\PipelineStage as PipelineStageData;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\ActionEngine\Pipelines\Models\PipelineStage;
use Kanvas\Apps\Models\Apps;

class PipelineStageMutation
{
    public function create(mixed $rootValue, array $request): PipelineStage
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $input = $request['input'];
        $pipeline = Pipeline::getByIdFromCompanyApp((int) $input['pipelines_id'], $company, $app);

        return new CreatePipelineStageAction(
            new PipelineStageData(
                pipeline: $pipeline,
                name: $input['name'],
                has_rotting_days: isset($input['has_rotting_days']) ? (int) $input['has_rotting_days'] : null,
                rotting_days: isset($input['rotting_days']) ? (int) $input['rotting_days'] : null,
                weight: isset($input['weight']) ? (int) $input['weight'] : null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): PipelineStage
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $pipelineStage = PipelineStage::getById((int) $request['id']);
        $pipeline = Pipeline::getByIdFromCompanyApp($pipelineStage->pipelines_id, $company, $app);

        $input = $request['input'];

        return new UpdatePipelineStageAction(
            $pipelineStage,
            new PipelineStageData(
                pipeline: $pipeline,
                name: $input['name'],
                slug: $input['slug'] ?? null,
                has_rotting_days: isset($input['has_rotting_days']) ? (int) $input['has_rotting_days'] : null,
                rotting_days: isset($input['rotting_days']) ? (int) $input['rotting_days'] : null,
                weight: isset($input['weight']) ? (int) $input['weight'] : null,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $pipelineStage = PipelineStage::getById((int) $request['id']);
        Pipeline::getByIdFromCompanyApp($pipelineStage->pipelines_id, $company, $app);

        return $pipelineStage->softDelete();
    }
}
