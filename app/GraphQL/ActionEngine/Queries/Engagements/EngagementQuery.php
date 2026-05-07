<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Queries\Engagements;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

class EngagementQuery
{
    public function engagementByFilter(mixed $root, array $args): Engagement
    {
        $data = $args['filter'];
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $entityType = match (strtolower($data['entity_type'])) {
            'lead' => 'leads_id',
            'people' => 'people_id',
        };

        $entityId = $data['entity_id'];

        if (Str::isUuid($entityId)) {
            $entity = match (strtolower($data['entity_type'])) {
                'lead' => Lead::query()->fromApp($app)->fromCompany($company)->where('uuid', $entityId)->firstOrFail(),
                'people' => People::query()->fromApp($app)->fromCompany($company)->where('uuid', $entityId)->firstOrFail()
            };

            $data['entity_id'] = $entity->id;
            $data['entity_id'] = $entity->id;
        } else {
            $data['entity_id'] = (int) $data['entity_id'];

            if ($data['entity_id'] <= 0) {
                throw new ModelNotFoundException('Invalid entity id either send a validate ID or UUID');
            }
        }

        $pipeline = Pipeline::getBySlug($data['slug'], $app, $company);
        $stage = $pipeline->stages()->where('slug', $data['status'])->first();

        if (! $stage) {
            throw new ModelNotFoundException('Pipeline ' . $pipeline->name . ' does not have a stage with slug ' . $data['status']);
        }

        //$pipelineStage
        $engagement = Engagement::query()
                        ->fromApp($app)
                        ->fromCompany($company)
                        ->where($entityType, $data['entity_id'])
                        ->where('slug', $data['slug'])
                        ->where('pipelines_stages_id', $stage->id)
                        ->first();

        if (! $engagement) {
            throw new ModelNotFoundException('Engagement ' . $data['slug'] . ' for lead ' . $entityId . ' not found');
        }

        return $engagement;
    }

    public function stageHistory(Engagement $engagement): Collection
    {
        return $engagement->stageHistory()->get();
    }

    public function isComplete(Engagement $engagement): bool
    {
        return $engagement->isComplete();
    }

    public function completionProgress(Engagement $engagement): array
    {
        return $engagement->completionProgress();
    }
}
