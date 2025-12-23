<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Leads\Actions\CreateLeadFirstEngagementMessageAction;

class TestCreateFirstMessageQuery
{
    public function getData(mixed $root, array $request): array
    {
        $company = auth()->user()->getCurrentCompany();
        $lead = Lead::getByIdFromCompanyApp(
            $request['lead_id'],
            $company,
            app(Apps::class)
        );
        $action = new CreateLeadFirstEngagementMessageAction(
            $lead
        );

        return array_merge($action->execute(), $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value));
    }
}
