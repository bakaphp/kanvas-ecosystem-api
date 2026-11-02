<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\ActionEngine\Engagements\Actions\ContinueEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\UpdateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as DataTransferObjectEngagement;
use Kanvas\ActionEngine\Engagements\DataTransferObject\UpdateEngagement as UpdateEngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Repositories\EngagementRepository;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

class EngagementMutation
{
    use ResolvesActingContext;

    public function startEngagement(mixed $rootValue, array $request): Engagement
    {
        $ctx = $this->actingContext();
        $request = $request['input'];

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $ctx->company, $ctx->app);
        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $ctx->company, $ctx->app) : $lead->people;
        /** @var Engagement|null $parentEngagement */
        $parentEngagement = ! empty($request['parent_id']) ? Engagement::getByIdFromCompanyApp((int) $request['parent_id'], $ctx->company, $ctx->app) : null;

        return new CreateEngagementAction(
            DataTransferObjectEngagement::from(
                $ctx->app,
                $ctx->company,
                $ctx->user,
                $lead,
                $request,
                $people,
                $parentEngagement,
            )
        )->execute();
    }

    public function continueEngagement(mixed $rootValue, array $request): Engagement
    {
        $ctx = $this->actingContext();
        $request = $request['input'];

        if (! ActionStatusEnum::validate($request['status'])) {
            throw new ValidationException('Invalid Engagement Status');
        }

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $ctx->company, $ctx->app);
        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $ctx->company, $ctx->app) : $lead->people;
        /** @var Engagement|null $parentEngagement */
        $parentEngagement = ! empty($request['parent_id']) ? Engagement::getByIdFromCompanyApp((int) $request['parent_id'], $ctx->company, $ctx->app) : null;

        return new ContinueEngagementAction(
            DataTransferObjectEngagement::from(
                $ctx->app,
                $ctx->company,
                $this->resolveContinuingUser(
                    $ctx->user,
                    $parentEngagement,
                    $lead,
                    $request['request_id']
                ),
                $lead,
                $request,
                $people,
                $parentEngagement,
            )
        )->execute();
    }

    /**
     * A key-only continue (customer opened/submitted, no rep logged in) stays attributed to the rep who
     * shared it, not the key's system user: engagement, message and the stakeholder push all name the
     * actor. Only the actor is borrowed; the company stays the key user's so tenant scoping never follows.
     */
    private function resolveContinuingUser(
        Users $user,
        ?Engagement $parentEngagement,
        Lead $lead,
        string $requestId
    ): Users {
        if (! $this->actingThroughAppKeyOnly()) {
            return $user;
        }

        $previousEngagement = $parentEngagement ?? EngagementRepository::findLatestForLeadEntity($lead, $requestId);
        $previousUser = $previousEngagement?->user;

        if ($previousUser === null || $previousUser->isDeleted() || ! $previousUser->isActive()) {
            return $user;
        }

        return $previousUser;
    }

    public function update(mixed $rootValue, array $request): Engagement
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        /** @var Engagement $engagement */
        $engagement = Engagement::getByUuidFromCompanyApp($request['uuid'], $ctx->company, $ctx->app);

        if (! empty($input['status']) && ! ActionStatusEnum::validate($input['status'])) {
            throw new ValidationException('Invalid Engagement Status');
        }

        return new UpdateEngagementAction(
            engagement: $engagement,
            data: new UpdateEngagementData(
                data: $input['data'],
                status: isset($input['status']) ? ActionStatusEnum::from($input['status']) : null,
                description: $input['description'] ?? null,
                via: $input['via'] ?? null,
            ),
        )->execute();
    }
}
