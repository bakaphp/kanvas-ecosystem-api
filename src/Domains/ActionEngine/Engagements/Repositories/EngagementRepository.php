<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

class EngagementRepository
{
    public static function findEngagementForLead(
        Lead $lead,
        string $actionSlug,
        string $stage,
        string $order = 'DESC'
    ): ?Engagement {
        return self::findEngagementForLeadBuilder(
            $lead,
            $actionSlug,
            $stage,
            $order
        )->first();
    }

    public static function findEngagementForLeadAndEntity(
        Lead $lead,
        string $actionSlug,
        string $stage,
        string $entityUuid,
        string $order = 'DESC'
    ): ?Engagement {
        return self::findEngagementForLeadBuilder(
            $lead,
            $actionSlug,
            $stage,
            $order
        )->where(
            'entity_uuid',
            $entityUuid
        )->first();
    }

    /**
     * A lead's ID-verification engagements are per-person: the main buyer and every co-buyer get
     * their own. `findEngagementForLead` filters only by lead + stage, so on a lead with participants
     * it returns whichever row is newest — which is how a co-buyer's report ends up attached to the
     * main buyer's message.
     */
    public static function findEngagementForLeadPeople(
        Lead $lead,
        People $people,
        string $actionSlug,
        string $stage,
        string $order = 'DESC'
    ): ?Engagement {
        return self::findEngagementForLeadBuilder(
            $lead,
            $actionSlug,
            $stage,
            $order
        )->where(
            'people_id',
            $people->getId()
        )->first();
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

        $engagementStage = $companyAction->pipeline
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
