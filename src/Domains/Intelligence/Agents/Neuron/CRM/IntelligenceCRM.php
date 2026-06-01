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
use Kanvas\Intelligence\Agents\Traits\HasTemporalContext;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

class IntelligenceCRM extends BaseKanvasAgent
{
    use HasTemporalContext;
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

        $timezone = $lead?->company?->timezone
            ?? $this->company?->timezone
            ?? 'UTC';

        $contextLines = array_merge($this->temporalContextLines($timezone), $lead !== null
            ? $this->leadContextLines($lead)
            : [
                'No lead is currently in scope. The prospect you are chatting with is NOT a CRM lead yet '
                . 'and does NOT know any lead_id — NEVER ask them for one. '
                . 'When the prospect shows real intent (asks to schedule, requests a demo, asks about pricing), '
                . 'YOU must call create_lead yourself using details gathered from the conversation '
                . '(name, company, email or phone, what they said). The create_lead tool returns a lead_id — '
                . 'use that returned lead_id for any subsequent lead-scoped tool calls (get_user_availability, '
                . 'create_calendar_event, etc.) in the SAME turn. Never invent a lead_id.',
            ]);

        return new SystemPrompt(
            background: [
                ...$background,
                ...$contextLines,
            ],
            steps: explode('\n', $steps),
            output: explode('\n', $output),
        )->__toString();
    }

    /**
     * Minimal lead context in the system prompt — IDs only. Full details
     * (name, company, contact, description, source, stage, owner, etc.) come
     * from get_lead_ref, which the LLM is directed to call once per
     * conversation when it doesn't already know the prospect. Keeps per-turn
     * token cost low for long sessions; trades one round-trip on turn 1 for
     * the cleaner economy on every subsequent turn.
     *
     * @return list<string>
     */
    private function leadContextLines(Lead $lead): array
    {
        return [
            "lead_id: {$lead->getId()}",
            "companies_id: {$lead->companies_id}",
            'A real lead is in scope for this conversation. Before you greet the prospect or ask them ANY question, '
                . 'call get_lead_ref with the lead_id above ONCE — it returns the prospect name, contact info, '
                . 'company, description / known context, source, pipeline stage, and owner. '
                . 'NEVER ask the prospect to tell you their name, company, role, or contact info: '
                . 'all of that is in the CRM and get_lead_ref returns it. '
                . 'You only need to call get_lead_ref once per conversation; remember the facts for the rest of the chat.',
        ];
    }

    #[Override]
    protected function tools(): array
    {
        // All lead-scoped and always-available tools are registered up-front so
        // the LLM can complete a full create_lead -> schedule -> book flow in
        // one turn. ResolvesLeadForTool catches hallucinated lead_ids and
        // returns a structured error pointing the LLM at create_lead instead
        // of crashing with "non-existing tool".
        $tools = [
            new CompanyInformationTool(),
            new CompanyWorkHoursTool(),
            new InventorySearchTool(),
            new ListAvailableProductsTool(),
            new VariantSearchTool(),
            new VariantDetailTool(),
            new ArtifactsTool(),
            new CommunicationChannelTool(),
            new CompanyIsHolidayTool(),
            new CompletionStatusTool(),
            new CalendarEventTool(),
            new UserAvailabilityTool(),
            new HandOffTool(),
            new LeadIntentTool(),
            new LeadRefTool(),
            new SimilarVehiclesTool(),
            new VehicleInterestTool(),
            new VehicleTradeInTool(),
        ];

        if ($this->entity instanceof Message) {
            $tools[] = new ContactCheckerTool($this->entity);
        }

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
