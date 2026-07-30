<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Enums\HandOffTypeEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Stop Contact')]
class StopContactTool extends Tool
{
    use ResolvesLeadForTool;

    private const EMAIL_CONTACT_TYPES = [
        ContactTypeEnum::EMAIL->value,
        ContactTypeEnum::PRIMARY_EMAIL->value,
        ContactTypeEnum::SECONDARY_EMAIL->value,
    ];

    public function __construct()
    {
        parent::__construct(
            name: 'stop_contact',
            description: 'Honor a prospect who asks to stop being contacted / unsubscribe / "stop" / "remove me" / "do not contact". '
                . 'This is a full do-not-contact: it opts their phone AND email out of future messages, turns off automated replies, '
                . 'logs a note on the lead, and notifies a human on the team. '
                . 'Call it as soon as the prospect clearly asks to stop hearing from the business. '
                . 'You may still send ONE short acknowledgement this turn (e.g. "Done — you won\'t hear from us again."), then stop.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead in scope for this conversation.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'What the prospect said, in their words (stored for the owner\'s audit).',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        ?string $reason = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $optedOut = [];
        $people = $lead->people;
        if ($people !== null) {
            if ($people->optOutPhoneContacts() > 0) {
                $optedOut[] = 'phone';
            }

            $emailsOptedOut = $people->contacts()
                ->whereIn('contacts_types_id', self::EMAIL_CONTACT_TYPES)
                ->where('is_opt_out', 0)
                ->update(['is_opt_out' => 1]);
            if ($emailsOptedOut > 0) {
                $optedOut[] = 'email';
            }
        }

        $lead->set('ai_mode', IntelligenceModeEnum::IDLE->value);
        $lead->set('do_not_contact', 1);
        if ($reason !== null && trim($reason) !== '') {
            $lead->set('do_not_contact_reason', trim($reason));
        }

        $noteBody = 'Prospect opted out of contact'
            . ($reason !== null && trim($reason) !== '' ? ' — "' . trim($reason) . '"' : '')
            . '. Automated messaging disabled across channels.';
        $noted = new RecordLeadNoteAction($lead)->execute($noteBody, 'opt-out') !== null;

        $handoffNotified = $this->notifyTeam($lead);

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'opted_out' => $optedOut,
            'ai_disabled' => true,
            'note_logged' => $noted,
            'team_notified' => $handoffNotified,
            'note' => 'Prospect opted out: all contacts marked do-not-contact, automated messaging disabled, note logged, team notified. '
                . 'Send ONE brief acknowledgement, then do not message this person again.',
        ];
    }

    /**
     * Alert a human that this lead opted out — a COMPLIANCE_INTERNAL handoff (notifies owner +
     * the handoff role via HandOffAction). Not a "keep chatting" handoff; it is an internal
     * compliance alert. Best-effort: never let a notification failure break the opt-out.
     */
    private function notifyTeam(Lead $lead): bool
    {
        try {
            new HandOffAction(
                lead: $lead,
                app: $lead->app,
                params: ['handoff_type' => HandOffTypeEnum::COMPLIANCE_INTERNAL->value],
            )->execute();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
