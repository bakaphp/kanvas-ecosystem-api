<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ArtifactsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CalendarEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CommunicationChannelTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyInformationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyIsHolidayTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyWorkHoursTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompletionStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ContactCheckerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadIntentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SimilarVehiclesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UserAvailabilityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleInterestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleTradeInTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListAvailableProductsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantSearchTool;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

class IntelligenceCRM extends BaseKanvasAgent
{
    use MergesRegisteredTools;

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        if ($this->entity === null || $this->user === null) {
            return new InMemoryChatHistory();
        }

        return new SalesAssistKanvasMessageHistory(
            app: $this->app,
            company: $this->company,
            user: $this->user,
            entity: $this->entity,
            threadId: $this->threadId,
        );
    }

    #[Override]
    public function instructions(): string
    {
        $role = $this->agent->role;
        $lead = $this->entity instanceof Lead ? $this->entity : null;

        $background = Blade::render($role['background'], ['lead' => $lead]);
        $steps = Blade::render($role['steps'], ['lead' => $lead]);
        $output = Blade::render($role['output'], ['lead' => $lead]);
        $background = explode('\n', $background);

        $contextLines = $lead !== null
            ? [
                "lead_id: {$lead->getId()}",
                "companies_id: {$lead->companies_id}",
            ]
            : [
                'No lead is currently in scope. Do NOT call lead-scoped tools '
                . '(get_user_availability, create_calendar_event, get_lead_intent, '
                . 'get_communication_channel, etc.) until the user provides a lead_id. '
                . 'Ask the user which lead to work with first.',
            ];

        return new SystemPrompt(
            background: [
                ...$background,
                ...$contextLines,
            ],
            steps: explode('\n', $steps),
            output: explode('\n', $output),
        )->__toString();
    }

    #[Override]
    protected function tools(): array
    {
        // Always-available tools — work regardless of whether a lead is in scope.
        // Inventory tools, CRM lookup tools that don't need a lead, etc.
        $tools = [
            new CompanyInformationTool(),
            new CompanyWorkHoursTool(),
            new InventorySearchTool(),
            new ListAvailableProductsTool(),
            new VariantSearchTool(),
            new VariantDetailTool(),
        ];

        // Lead-scoped tools — only exposed when the current session entity is
        // an actual Lead. Hides them from the LLM when chatting user-scoped so
        // it can't hallucinate a lead_id to satisfy a required parameter.
        if ($this->entity instanceof Lead) {
            $tools[] = new ArtifactsTool();
            $tools[] = new CommunicationChannelTool();
            $tools[] = new CompanyIsHolidayTool();
            $tools[] = new CompletionStatusTool();
            $tools[] = new CalendarEventTool();
            $tools[] = new UserAvailabilityTool();
            $tools[] = new HandOffTool();
            $tools[] = new LeadIntentTool();
            $tools[] = new LeadRefTool();
            $tools[] = new SimilarVehiclesTool();
            $tools[] = new VehicleInterestTool();
            $tools[] = new VehicleTradeInTool();
        }

        if ($this->entity instanceof Message) {
            $tools[] = new ContactCheckerTool($this->entity);
        }

        // CreateLeadTool exposed whenever we have an authenticated context so
        // user-scoped chats can promote into lead-scoped after the first call.
        if ($this->app !== null && $this->company !== null && $this->user !== null) {
            $tools[] = new CreateLeadTool($this->app, $this->company, $this->user, $this->session);
        }

        return $this->mergeRegisteredTools(
            $tools,
            $this->agent,
            CapabilityFrameworkEnum::NEURON
        );
    }
}
