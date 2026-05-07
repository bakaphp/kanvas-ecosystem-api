<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Pipelines;

use Kanvas\ActionEngine\Pipelines\Actions\CreatePipelineAction;
use Kanvas\ActionEngine\Pipelines\Actions\UpdatePipelineAction;
use Kanvas\ActionEngine\Pipelines\DataTransferObject\Pipeline as PipelineData;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;

class PipelineMutation
{
    public function create(mixed $rootValue, array $request): Pipeline
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $input = $request['input'];

        return new CreatePipelineAction(
            data: new PipelineData(
                name: $input['name'],
                slug: $input['slug'] ?? null,
                weight: isset($input['weight']) ? (int) $input['weight'] : null,
                is_default: $input['is_default'] ?? null,
            ),
            user: $user,
            app: $app,
            companiesId: $company->getId(),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Pipeline
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $pipeline = Pipeline::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $input = $request['input'];

        return new UpdatePipelineAction(
            $pipeline,
            new PipelineData(
                name: $input['name'],
                slug: $input['slug'] ?? null,
                weight: isset($input['weight']) ? (int) $input['weight'] : null,
                is_default: $input['is_default'] ?? null,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $pipeline = Pipeline::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        if ($pipeline->companyActions()->where('is_deleted', 0)->exists()) {
            throw new \Exception('Cannot delete pipeline that is in use by company actions.');
        }

        if ($pipeline->actions()->where('is_deleted', 0)->exists()) {
            throw new \Exception('Cannot delete pipeline that is in use by actions.');
        }

        return $pipeline->softDelete();
    }
}
