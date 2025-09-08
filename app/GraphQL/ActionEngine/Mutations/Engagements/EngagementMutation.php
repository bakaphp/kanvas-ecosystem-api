<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Kanvas\ActionEngine\Engagements\Actions\ContinueEngagementAction;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as DataTransferObjectEngagement;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;

class EngagementMutation
{
    /**
     * @todo add test
     */
    public function startEngagement(mixed $rootValue, array $request): Engagement
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $request = $request['input'];

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);
        $user->follow($lead);

        $people = ! empty($request['people_id']) ? People::getByIdFromCompanyApp($request['people_id'], $company, $app) : $lead->people;
        $receiver = ! empty($request['receiver_id']) ? LeadReceiver::getByIdFromCompanyApp($request['receiver_id'], $company, $app) : ($lead->receiver ?? LeadReceiver::getDefault($company, $app));
        $requestId = $request['request_id'];
        //$parentAction = $this->getActionInfo($app, $request['action']);
        //$action = $parentAction['parent']ddfad;

        return new CreateEngagementAction(
            DataTransferObjectEngagement::from(
                $app,
                $company,
                $user,
                $lead,
                $request,
                $people
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
        $receiver = ! empty($request['receiver_id']) ? LeadReceiver::getByIdFromCompanyApp($request['receiver_id'], $company, $app) : ($lead->receiver ?? LeadReceiver::getDefault($company, $app));
        $requestId = $request['request_id'];
        $action = $request['action'];
        $checkListId = $request['task_id'] ?? 0;
        $source = $request['source'];
        $via = $request['via'] ?? 'copy';
        $data = $request['data'] ?? [];
        $status = $request['status'];

        return new ContinueEngagementAction(
            DataTransferObjectEngagement::from(
                $app,
                $company,
                $user,
                $lead,
                $request,
                $people
            )
        )->execute();
    }
}
