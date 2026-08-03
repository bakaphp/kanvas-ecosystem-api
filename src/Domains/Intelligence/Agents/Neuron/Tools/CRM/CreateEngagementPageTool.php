<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Str;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Create Engagement Page', category: 'crm')]
class CreateEngagementPageTool extends Tool
{
    public function __construct(private readonly Agent $agent)
    {
        parent::__construct(
            name: 'create_engagement_page',
            description: 'Create one tracked Action Engine page for a lead and return its action URL. '
                . 'Use an action slug such as view-vehicle, get-docs, credit-app or add-trade. '
                . 'This tool does not contact the customer; pass the returned action_link to send_sms or send_email.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead that will own the engagement page.',
                required: true,
            ),
            new ToolProperty(
                name: 'action',
                type: PropertyType::STRING,
                description: 'Action slug for the page, for example view-vehicle, get-docs, credit-app or add-trade.',
                required: true,
            ),
            new ToolProperty(
                name: 'data',
                type: PropertyType::OBJECT,
                description: 'Action-specific page payload. For view-vehicle, provide a products array and mark the selected vehicle with interested=true.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id, string $action, array $data = []): array
    {
        $action = trim($action);
        if ($action === '') {
            return [
                'status' => 'error',
                'message' => 'The action slug is required.',
            ];
        }

        $app = $this->agent->app;
        $company = $this->agent->company;
        $user = $this->agent->user;
        if ($app === null || $company === null || $user === null) {
            return [
                'status' => 'error',
                'message' => 'The agent must have an app, company, and acting user before creating an engagement.',
            ];
        }

        try {
            $lead = Lead::getByIdFromCompanyApp($lead_id, $company, $app);
        } catch (Throwable) {
            return [
                'status' => 'error',
                'message' => "Lead {$lead_id} does not exist in the current app and company.",
            ];
        }

        try {
            $engagement = $this->createEngagement(new EngagementData(
                app: $app,
                company: $company,
                user: $user,
                lead: $lead,
                action: $action,
                requestId: (string) Str::uuid(),
                source: 'agent',
                status: ActionStatusEnum::SENT,
                people: $lead->people,
                via: 'agent',
                data: $data,
            ));
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The engagement page could not be created. Verify the action and company configuration.',
            ];
        }

        $message = $engagement->message;
        $messageData = is_array($message?->message) ? $message->message : [];
        $actionLink = $messageData['action_link'] ?? null;

        if (! is_string($actionLink) || $actionLink === '') {
            return [
                'status' => 'error',
                'message' => 'The engagement was created but no action URL was generated.',
                'engagement_id' => $engagement->getId(),
                'engagement_uuid' => (string) $engagement->uuid,
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead->getId(),
            'action' => $action,
            'engagement_id' => $engagement->getId(),
            'engagement_uuid' => (string) $engagement->uuid,
            'message_id' => $message?->getId(),
            'action_link' => $actionLink,
            'delivery' => 'not_sent',
        ];
    }

    protected function createEngagement(EngagementData $data): Engagement
    {
        return new CreateEngagementAction($data)->execute();
    }
}
