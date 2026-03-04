<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries;

use Illuminate\Support\Facades\Blade;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Repositories\FollowUpRepository;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Tools\VehicleInterestTool;

class FollowUpPromptQuery
{
    public function getAgentFollowUpPrompts(mixed $root, array $request): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = app(CompaniesBranches::class)->company;

        $lead = Lead::getByIdFromCompanyApp((int) $request['lead_id'], $company, $app);

        // Use provided stage_id or default to lead's current stage
        $stageId = isset($request['stage_id']) ? (int) $request['stage_id'] : $lead->pipeline_stage_id;
        $stage = PipelineStage::find($stageId);

        $aiFollowUpType = $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value);
        if (! $aiFollowUpType) {
            $aiFollowUpType = $lead->get(IntelligenceModeEnum::DEFAULT_AI_FOLLOW_UP_TYPE->value)
                ?? FollowUpTypeEnum::LEAD_FOLLOW_UP->value;
        }

        $followUp = FollowUpRepository::getFollowUpFromLead($lead, $aiFollowUpType);

        if (! $followUp) {
            return [
                'lead_id' => $lead->getId(),
                'stage_id' => $stageId,
                'stage_name' => $stage?->name,
                'is_simulation' => $stageId !== $lead->pipeline_stage_id,
                'follow_up' => null,
                'sessions' => [],
            ];
        }

        $followUpDay = $followUp->days()
            ->where('pipeline_stages_id', $stageId)
            ->where('is_deleted', 0)
            ->orderBy('weight', 'ASC')
            ->first();

        if (! $followUpDay) {
            return [
                'lead_id' => $lead->getId(),
                'stage_id' => $stageId,
                'stage_name' => $stage?->name,
                'is_simulation' => $stageId !== $lead->pipeline_stage_id,
                'follow_up' => [
                    'id' => $followUp->getId(),
                    'name' => $followUp->name,
                ],
                'follow_up_day' => null,
                'sessions' => [],
            ];
        }

        $templates = $followUpDay->templates()
            ->where('is_deleted', 0)
            ->get()
            ->keyBy('communication_channel');

        $sessions = Session::where('entity_namespace', '=', Lead::class)
            ->where('entity_id', '=', $lead->getId())
            ->where('is_deleted', 0)
            ->fromApp($app)
            ->fromCompany($company)
            ->get();

        $agent = $this->getFollowUpAgent($lead);

        $sessionPrompts = [];
        foreach ($sessions as $session) {
            $channel = $session->getChannel();
            $template = $templates->get($channel);

            if (! $template) {
                continue;
            }

            $prompt = $this->renderPrompt($lead, $session, $template->template, $followUpDay, $agent);

            $sessionPrompts[] = [
                'session_id' => $session->getId(),
                'session_uuid' => $session->uuid,
                'channel' => $channel,
                'template' => [
                    'id' => $template->getId(),
                    'name' => $template->name,
                    'content' => $template->template,
                ],
                'prompt' => $prompt,
                'day' => (float) $followUpDay->pipelineStage->weight,
            ];
        }

        return [
            'lead_id' => $lead->getId(),
            'stage_id' => $stageId,
            'stage_name' => $stage?->name,
            'is_simulation' => $stageId !== $lead->pipeline_stage_id,
            'follow_up' => [
                'id' => $followUp->getId(),
                'name' => $followUp->name,
                'config' => $followUp->config,
            ],
            'follow_up_day' => [
                'id' => $followUpDay->getId(),
                'name' => $followUpDay->name,
                'time_value' => $followUpDay->time_value,
                'time_unit' => $followUpDay->time_unit,
                'send_message' => $followUpDay->send_message ?? false,
            ],
            'sessions' => $sessionPrompts,
        ];
    }

    protected function getFollowUpAgent(Lead $lead): ?Agent
    {
        return Agent::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('name', 'FollowUpEngagerAgent')
            ->first();
    }

    protected function renderPrompt(
        Lead $lead,
        Session $session,
        string $template,
        mixed $followUpDay,
        ?Agent $agent
    ): ?string {
        if (! $agent) {
            return null;
        }

        $companyWorkHour = new CompanyWorkHoursTool($lead)->execute();
        $vehicleInterest = new VehicleInterestTool($lead)->execute();
        $contentSession = new CreateContentSessionAction($session);
        $conversationHistory = $this->mapConversationHistory($session, $lead);

        $data = [
            'templates' => $template,
            'conversation_history' => $conversationHistory,
            'context' => [
                'company' => $lead->company,
                'lead' => $lead,
                'lead_owner' => $lead->owner,
            ],
            'work_hours_status' => $companyWorkHour,
            'is_engagement' => $lead->get('is_engagement') ? 1 : 0,
            'holiday_status' => new CompanyIsHolidayTool($lead)->execute(),
            'agent' => $session->agent,
            'vehicle_interest' => $vehicleInterest,
            'shareMyVehicle' => null,
            'day' => (float) $followUpDay->pipelineStage->weight,
        ];

        $background = $agent->role['background'] ?? [];

        if (empty($background)) {
            return null;
        }

        return Blade::render(implode(' ', $background), $data);
    }

    protected function mapConversationHistory(Session $session, Lead $lead): array
    {
        $conversationMessages = $session->channel->messages()->get()
            ->map(fn ($message) => [
                'created_at' => $message->created_at,
                'user' => $message->slug ? 'lead' : 'agent',
                'message' => $message->message,
                'type' => 'conversation',
            ]);

        $agentNotesMessages = $lead->notes ? $lead->notes->messages()->get()
            ->map(fn ($message) => [
                'created_at' => $message->created_at,
                'user' => 'agent',
                'message' => $message->message,
                'type' => 'note',
            ]) : collect([]);

        return $conversationMessages
            ->concat($agentNotesMessages)
            ->sortBy('created_at')
            ->map(fn (array $item): array => [
                'user' => $item['user'],
                'message' => $item['message'],
            ])
            ->values()
            ->toArray();
    }
}
