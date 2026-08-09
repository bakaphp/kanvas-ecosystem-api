<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\CRM;

use Illuminate\Support\Facades\Blade;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ArtifactsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\BookingOptionsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CalendarEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CancelCalendarEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CaptureConversationLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CommunicationChannelTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyInformationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyIsHolidayTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyWorkHoursTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompletionStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ContactCheckerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FaqLookupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadIntentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\RescheduleCalendarEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\StopContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TakeMessageTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UserAvailabilityTool;
use Kanvas\Intelligence\Agents\Traits\HasCustomerPersona;
use Kanvas\Intelligence\Agents\Traits\HasTemporalContext;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Override;

/**
 * Inbound Lead & Booking Concierge — the front-desk receptionist archetype.
 *
 * Customer-facing (ConversesWithCustomer): intercepts inbound messages on a channel, answers FAQs
 * from the company knowledge base (get_company_faqs), qualifies + updates the prospect, books
 * appointments INTERNALLY (Kanvas Event via create_calendar_event — no external calendar tool),
 * takes messages, and honors opt-outs. Memory is prospect-isolated exactly like SalesAgent.
 */
#[AgentTypeDefinition(
    name: 'Receptionist Agent',
    description: 'Inbound receptionist for prospect chat — answers FAQs, qualifies and updates leads, books internal appointments, takes messages, honors opt-outs, and hands off to a human when needed.',
    provider: 'neuron',
)]
class ReceptionistAgent extends BaseKanvasAgent implements ConversesWithCustomer
{
    use HasCustomerPersona;
    use HasTemporalContext;
    use MergesRegisteredTools;

    private const LOCAL_BACKGROUND = 'You are the front-desk receptionist. You answer inbound messages instantly, 24/7, the way a great human receptionist would: warm, brief, and genuinely helpful. You are the first point of contact — you greet the person, answer their questions, understand what they need, and get qualified prospects booked onto the calendar.';

    private const LOCAL_STEPS = "Greet the person warmly and briefly.\n"
        . 'For any question about hours, pricing, location, services, policies or anything about the business, call get_company_faqs FIRST and answer from what it returns; use get_company_work_hours for open/closed status and get_company_information for contact details.'
        . "\nAsk only the qualifying questions you need to tell whether this person is a fit for the business, and save what you learn with update_lead (contact details, budget, service needed, urgency, timeline, disposition)."
        . "\nWhen the prospect shows real intent (wants to book, get a quote, schedule, or speak with someone), call create_lead using details gathered from the conversation, then use get_booking_options + get_user_availability + create_calendar_event to book the appointment in the SAME turn."
        . "\nIf the prospect asks to leave a message for someone, use take_message. If they ask to stop being contacted / unsubscribe, use stop_contact."
        . "\nIf you cannot help, the request is out of scope, or they ask for a person, use hand_off.";

    private const LOCAL_OUTPUT = "Keep replies short and conversational, like a text message — one or two sentences.\n"
        . 'Never expose internal IDs, system details, or that you are an AI unless directly asked.'
        . "\nNever ask the person for a lead_id or any internal identifier — they don't have one.";

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
            currentLead: $this->currentLead,
        );
    }

    #[Override]
    public function instructions(): string
    {
        $role = (array) ($this->agent->role ?? []);
        $lead = $this->resolveLeadForTurn();
        $context = ['lead' => $lead];

        $background = $this->renderOrDefault(
            $role['background'] ?? null,
            self::LOCAL_BACKGROUND,
            $context
        );
        $steps = $this->renderOrDefault(
            $role['steps'] ?? null,
            self::LOCAL_STEPS,
            $context
        );
        $output = $this->renderOrDefault(
            $role['output'] ?? null,
            self::LOCAL_OUTPUT,
            $context
        );

        $timezone = $lead?->company?->timezone
            ?? $this->company?->timezone
            ?? 'UTC';

        $contextLines = array_merge($this->temporalContextLines($timezone), $lead !== null
            ? $this->leadContextLines($lead)
            : [
                'No lead is currently in scope. The prospect you are chatting with is NOT a CRM lead yet '
                . 'and does NOT know any lead_id — NEVER ask them for one. '
                . 'When the prospect shows real intent (asks to book, schedule, requests a quote or a call), '
                . 'YOU must call create_lead yourself using details gathered from the conversation '
                . '(name, company, email or phone, what they said). The create_lead tool returns a lead_id — '
                . 'use that returned lead_id for any subsequent lead-scoped tool calls (get_user_availability, '
                . 'create_calendar_event, update_lead, etc.) in the SAME turn. Never invent a lead_id.',
            ]);

        return new SystemPrompt(
            background: [
                ...$this->personaLines(),
                ...explode("\n", $background),
                ...$contextLines,
            ],
            steps: explode("\n", $steps),
            output: explode("\n", $output),
        )->__toString();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderOrDefault(mixed $dbValue, string $localDefault, array $context): string
    {
        if (is_string($dbValue) && trim($dbValue) !== '') {
            return Blade::render($dbValue, $context);
        }

        return $localDefault;
    }

    /**
     * Only IDs in the system prompt — full details come from get_lead_ref, called once per
     * conversation. Mirrors SalesAgent to keep per-turn token cost low.
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
        $tools = [
            new CompanyInformationTool(),
            new CompanyWorkHoursTool(),
            new CompanyIsHolidayTool(),
            new ArtifactsTool(),
            new CommunicationChannelTool(),
            new CompletionStatusTool(),
            new CalendarEventTool(),
            new RescheduleCalendarEventTool(),
            new CancelCalendarEventTool(),
            new UserAvailabilityTool(),
            new BookingOptionsTool(),
            new HandOffTool(),
            new LeadIntentTool(),
            new LeadRefTool(),
            new UpdateLeadTool(),
            new StopContactTool(),
        ];

        if ($this->app !== null && $this->company !== null && $this->user !== null) {
            // Context so the receptionist note is attributed to the agent's own user, not the shared AI user.
            $tools[] = new TakeMessageTool()->withContext($this->app, $this->company, $this->user);
        }

        if ($this->entity instanceof Message) {
            $tools[] = new ContactCheckerTool($this->entity);
        }

        if ($this->app !== null && $this->company !== null) {
            $tools[] = new FaqLookupTool($this->app, $this->company);
        }

        if ($this->app !== null && $this->company !== null && $this->user !== null) {
            $tools[] = new CaptureConversationLeadTool(
                $this->app,
                $this->company,
                $this->user,
                $this->session
            );
        }

        return $this->mergeRegisteredTools(
            $tools,
            $this->agent,
            CapabilityFrameworkEnum::NEURON
        );
    }
}
