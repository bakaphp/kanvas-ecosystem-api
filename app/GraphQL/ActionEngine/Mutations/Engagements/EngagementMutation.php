<?php

declare(strict_types=1);

namespace App\GraphQL\ActionEngine\Mutations\Engagements;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

class EngagementMutation
{
    public function startEngagement(mixed $rootValue, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $request = $request['input'];

        $lead = Lead::getByIdFromCompanyApp($request['lead_id'], $company, $app);
        $people = People::getByIdFromCompanyApp($request['people_id'], $company, $app);
        $requestId = $request['request_id'];
        $action = $request['action'];

        $companyAction = CompanyAction::getByAction(
            Action::getBySlug($action, $company),
            $company,
            $app,
            $lead->branch
        );

        //save share history en company action history
        //generate link
        //create msg
        //create engagement
        //return engagement

        return [];
    }
}
