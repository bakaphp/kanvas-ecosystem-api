<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\PipelinesStages\Actions\CreateMessageFollowUpAction;
use Kanvas\Intelligence\Sessions\Models\Session;

class FollowUpPromptQuery
{
    public function getFollowUpPrompt(mixed $root, array $request): string
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = app()->bound(CompaniesBranches::class)
            ? app(CompaniesBranches::class)->company
            : $user->getCurrentCompany();

        $lead = Lead::getByIdFromCompanyApp((int) $request['lead_id'], $company, $app);
        $pipelineStage = PipelineStage::getById((int) $request['pipeline_stage_id'], $app);
        $session = Session::getByUuidFromCompanyApp($request['session_id'], $company, $app);

        $action = new CreateMessageFollowUpAction(
            lead: $lead,
            pipelineStage: $pipelineStage,
            session: $session,
            messageTemplate: $request['message_template'],
            day: (float) $request['day'],
            onlyPrompt: true
        );

        return $action->execute() ?? '';
    }
}
