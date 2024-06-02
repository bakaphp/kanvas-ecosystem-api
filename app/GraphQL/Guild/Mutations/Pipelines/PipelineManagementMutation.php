<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Pipelines;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Pipelines\Actions\CreatePipelineAction;
use Kanvas\Guild\Pipelines\Actions\UpdatePipelineAction;
use Kanvas\Guild\Pipelines\DataTransferObject\Pipeline;
use Kanvas\Guild\Pipelines\Models\Pipeline as ModelsPipeline;
use Throwable;

class PipelineManagementMutation
{
    /**
     * Create new pipeline
     */
    public function create(mixed $root, array $req): ModelsPipeline
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $app = app(Apps::class);

        $pipeline = new CreatePipelineAction(
            Pipeline::viaRequest(
                $user,
                $branch,
                $app,
                $req['input']
            )
        );

        return $pipeline->execute();
    }

    public function update(mixed $root, array $req): ModelsPipeline
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $branch = $user->getCurrentBranch();
        $id = (int) $req['id'];
        $app = app(Apps::class);

        $pipeline = ModelsPipeline::getByIdFromCompany($id, $company);

        $updatePipeline = new UpdatePipelineAction(
            $pipeline,
            Pipeline::viaRequest(
                $user,
                $branch,
                $app,
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

        $pipeline = ModelsPipeline::getByIdFromCompany($id, $company);

        //cant delete if its been used by a lead
        if ($pipeline->leads()->count() > 0) {
            throw new ValidationException('Can\'t Delete pipeline is being used by a lead');
        }

        try {
            if ($pipeline->isDefault()) {
                $nextPipeline = ModelsPipeline::fromCompany($company)
                    ->notDeleted()
                    ->where('id', '!=', $pipeline->getId())
                    ->where('is_default', '0')
                    ->orderBy('weight', 'ASC')
                    ->firstOrFail();

                $nextPipeline->switchDefaultPipeline();
            }
        } catch (Throwable $e) {
            throw new ValidationException('Can\'t Delete you have to have at least one default pipeline');
        }

        return $pipeline->softDelete();
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function restore(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $id = (int) $req['id'];

        return ModelsPipeline::where('id', $id)
            ->where('is_deleted', '1')
            ->fromCompany($company)
            ->firstOrFail()->restoreRecord();
    }
}
