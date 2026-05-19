<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\FollowUp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\FollowUp\Actions\CreateFollowUpTemplateAction;
use Kanvas\Intelligence\FollowUp\Actions\UpdateFollowUpTemplateAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpTemplate as FollowUpTemplateData;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;

class FollowUpTemplateMutation
{
    public function create(mixed $rootValue, array $request): FollowUpTemplate
    {
        $app = app(Apps::class);
        $input = $request['input'];

        $followUpDay = FollowUpDay::getById((int) $input['follow_up_day_id'], $app);

        return new CreateFollowUpTemplateAction(
            new FollowUpTemplateData(
                followUpDay: $followUpDay,
                communication_channel: $input['communication_channel'],
                name: $input['name'],
                template: $input['template'],
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): FollowUpTemplate
    {
        $app = app(Apps::class);
        $input = $request['input'];

        $followUpTemplate = FollowUpTemplate::getById((int) $request['id'], $app);
        $followUpDay = FollowUpDay::getById($followUpTemplate->follow_up_days_id, $app);

        return new UpdateFollowUpTemplateAction(
            $followUpTemplate,
            new FollowUpTemplateData(
                followUpDay: $followUpDay,
                communication_channel: $input['communication_channel'] ?? $followUpTemplate->communication_channel,
                name: $input['name'] ?? $followUpTemplate->name,
                template: $input['template'] ?? $followUpTemplate->template,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $followUpTemplate = FollowUpTemplate::getById((int) $request['id'], app(Apps::class));

        return (bool) $followUpTemplate->delete();
    }
}
