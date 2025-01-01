<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Guild\Leads\Models\Lead;

class EngagementRepository
{
    public static function findEngagementForLead(
        Lead $lead,
        string $actionSlug,
        string $stage,
        string $order = 'DESC'
    ): ?Engagement {
        return self::findEngagementForLeadBuilder($lead, $actionSlug, $stage, $order)->first();
    }

    public static function findEngagementForLeaAndEntity(
        Lead $lead,
        string $actionSlug,
        string $stage,
        string $entityUuid,
        string $order = 'DESC'
    ): ?Engagement {
        return self::findEngagementForLeadBuilder($lead, $actionSlug, $stage, $order)->where('entity_uuid', $entityUuid)->first();
    }

    public static function findEngagementForLeadBuilder(
        Lead $lead,
        string $actionSlug,
        string $stage,
        string $order = 'DESC'
    ): Builder {
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $companyAction = CompanyAction::getByAction(
            Action::getBySlug($actionSlug, $lead->company),
            $lead->company,
            $lead->app
        );

        if (! $companyAction->pipeline()->exists()) {
            throw new ModelNotFoundException("Pipeline not found for action '{$actionSlug}'");
        }

        $engagementStage = $companyAction->pipeline()
        ->stages()
        ->where('slug', $stage)
        ->firstOrFail();

        return Engagement::query()->where('leads_id', $lead->getId())
            ->where('pipelines_stages_id', $engagementStage->getId())
            ->fromApp($lead->app)
            ->fromCompany($lead->company)
            ->notDeleted()
            ->orderBy('id', $order);
    }
}
