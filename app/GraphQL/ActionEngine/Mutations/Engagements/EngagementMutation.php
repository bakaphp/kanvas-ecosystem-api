<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Kanvas\ActionEngine\Engagements\Actions\ContinueEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\UpdateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as DataTransferObjectEngagement;
use Kanvas\ActionEngine\Engagements\DataTransferObject\UpdateEngagement as UpdateEngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

class EngagementMutation
{
    public function startEngagement(mixed $rootValue, array $request): Engagement
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $request = $request['input'];

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);
        $user->follow($lead);

        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $company, $app) : $lead->people;
        /** @var Engagement|null $parentEngagement */
        $parentEngagement = ! empty($request['parent_id']) ? Engagement::getByIdFromCompanyApp((int) $request['parent_id'], $company, $app) : null;

        return new CreateEngagementAction(
            DataTransferObjectEngagement::from(
                $app,
                $company,
                $user,
                $lead,
                $request,
                $people,
                $parentEngagement,
            )
        )->execute();
    }

    public function continueEngagement(mixed $rootValue, array $request): Engagement
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $request = $request['input'];

        if (! ActionStatusEnum::validate($request['status'])) {
            throw new ValidationException('Invalid Engagement Status');
        }

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);
        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $company, $app) : $lead->people;
        /** @var Engagement|null $parentEngagement */
        $parentEngagement = ! empty($request['parent_id']) ? Engagement::getByIdFromCompanyApp((int) $request['parent_id'], $company, $app) : null;

        return new ContinueEngagementAction(
            DataTransferObjectEngagement::from(
                $app,
                $company,
                $user,
                $lead,
                $request,
                $people,
                $parentEngagement,
            )
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Engagement
    {
        $app = app(Apps::class);
        $user = auth()->user();
        /** @var \Kanvas\Companies\Models\Companies $company */
        $company = $user->getCurrentCompany();
        /** @var array $input */
        $input = $request['input'];

        /** @var Engagement $engagement */
        $engagement = Engagement::getByUuidFromCompanyApp($request['uuid'], $company, $app);

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
