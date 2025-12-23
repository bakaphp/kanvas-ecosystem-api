<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Leads\Actions\CreateLeadFirstEngagementMessageAction;

class TestCreateFirstMessageQuery
{
    public function getData(mixed $root, array $request): array
    {
        $lead = Lead::getById($request['lead_id']);
        $action = new CreateLeadFirstEngagementMessageAction(
            $lead
        );

        return array_merge($action->execute(), $lead->get(ConfigurationEnum::LEAD_CONTEXT_INFO->value));
    }
}
