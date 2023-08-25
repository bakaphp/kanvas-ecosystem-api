<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Pipelines;

use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Pipelines\Actions\CreatePipelineAction;
use Kanvas\Guild\Pipelines\DataTransferObject\Pipeline;
use Kanvas\Guild\Pipelines\Models\Pipeline as ModelsPipeline;

class PipelineManagementMutation
{
    /**
     * Create new lead
     */
    public function create(mixed $root, array $req): ModelsPipeline
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();

        $pipeline = new CreatePipelineAction(
            Pipeline::viaRequest(
                $user,
                $branch,
                $req['input']
            )
        );

        return $pipeline->execute();
    }
}
