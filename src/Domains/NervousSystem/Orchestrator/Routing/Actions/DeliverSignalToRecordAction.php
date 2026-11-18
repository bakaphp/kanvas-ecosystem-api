<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Actions\RecordOrganizationNoteAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Jobs\WakeAgentInSessionJob;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\SignalDeliveryResult;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\MeetingAgentEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\SignalDeliveryStatusEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Services\MeetingAgentLocatorService;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\Social\Messages\Models\Message;

/**
 * Hands a routed signal to a lead (Sales Manager) or an organization (PM): its content is written
 * whole as an internal note on the record, and the agent is woken with a short instruction pointing
 * at that note. The wording follows the signal's kind (meeting, email, ...), so nothing here is
 * specific to transcripts. The agent pages through it with read_message_content rather than carrying it in its
 * prompt, and the `internal` verb keeps it out of a customer-facing agent's history.
 *
 * Nothing is written when the company has no agent for the role: a signal nobody will read is
 * left in the webhook request and the routing log instead.
 */
class DeliverSignalToRecordAction
{
    private const int LOCK_SECONDS = 30;
    private const int LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly RoutingCandidate $target,
        private readonly InboundSignal $signal
    ) {
        if ($target->project() !== null) {
            throw new InvalidArgumentException('Only a lead or an organization is delivered here.');
        }
    }

    public function execute(): SignalDeliveryResult
    {
        /** @var Lead|Organization $record */
        $record = $this->target->record;

        /** @var MeetingAgentEnum $role */
        $role = MeetingAgentEnum::forTarget($this->target->type);

        $agent = new MeetingAgentLocatorService()->resolve($role, $record->app, $record->company);
        if ($agent === null || $agent->user === null) {
            return new SignalDeliveryResult(
                SignalDeliveryStatusEnum::NO_AGENT,
                sprintf('No %s agent in this company; nothing was done.', $this->target->type->value)
            );
        }

        $slug = $this->noteSlug();

        // Nothing in the schema refuses a second note with this slug, so the check and the write are
        // serialized: two copies of one signal arriving together would otherwise both pass the check.
        $note = Cache::lock('signal-delivery:' . $record->apps_id . ':' . $slug, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            fn (): Message|false|null => $this->alreadyDelivered($record, $slug)
                ? false
                : $this->recordNote($record, $slug)
        );

        if ($note === false) {
            return new SignalDeliveryResult(
                SignalDeliveryStatusEnum::DUPLICATE,
                sprintf('Already delivered to %s.', $this->target->key()),
                $agent
            );
        }

        if ($note === null) {
            return new SignalDeliveryResult(
                SignalDeliveryStatusEnum::FAILED,
                sprintf('The note could not be written on %s.', $this->target->key()),
                $agent
            );
        }

        WakeAgentInSessionJob::dispatch(
            app: $record->app,
            agent: $agent,
            session: Session::firstOrCreateForEntity($record, extraKey: ['agents_id' => $agent->getId()]),
            instruction: $this->instruction($note),
            user: $agent->user,
        );

        return new SignalDeliveryResult(
            SignalDeliveryStatusEnum::DELIVERED,
            sprintf('Delivered to %s and woke agent #%d.', $this->target->key(), $agent->getId()),
            $agent,
            (int) $note->getId(),
        );
    }

    /**
     * The note is the delivery record: its slug names the signal and the record it landed on. The
     * source's id is hashed to keep it inside the column, and a source with no id is keyed by its
     * content so a resend of the same text still dedups.
     */
    private function noteSlug(): string
    {
        return sprintf(
            '%s-%s-%s-%d-%s',
            $this->signal->kind->noun(),
            $this->signal->source->value,
            $this->target->type->value,
            $this->target->id(),
            sha1($this->signal->externalId !== '' ? $this->signal->externalId : $this->signal->content),
        );
    }

    private function alreadyDelivered(Lead|Organization $record, string $slug): bool
    {
        return Message::query()
            ->withTrashed()
            ->fromApp($record->app)
            ->where('slug', $slug)
            ->exists();
    }

    private function recordNote(Lead|Organization $record, string $slug): ?Message
    {
        $recorder = $record instanceof Lead
            ? new RecordLeadNoteAction($record)
            : new RecordOrganizationNoteAction($record);

        $kind = $this->signal->kind;

        return $recorder->execute(
            body: $this->noteBody(),
            tag: $kind->noun() . '-' . $kind->bodyLabel(),
            messageTypeVerb: LeadMessageTypeEnum::INTERNAL->value,
            slug: $slug,
        );
    }

    private function noteBody(): string
    {
        $metadata = $this->signal->metadata;
        $kind = $this->signal->kind;

        $people = array_map(
            static fn (array $actor): string => trim($actor['name'] . ($actor['email'] !== null ? " <{$actor['email']}>" : '')),
            $this->signal->actors
        );

        $sections = [
            sprintf(
                '%s: "%s"%s',
                ucfirst($kind->noun()),
                $this->signal->title,
                $this->signal->occurredAt !== null ? " ({$this->signal->occurredAt})" : ''
            ),
            $people !== [] ? 'Participants: ' . implode(', ', $people) : null,
            ! empty($metadata['report_url']) ? 'Report: ' . $metadata['report_url'] : null,
            ! empty($metadata['summary']) ? "Summary:\n" . $metadata['summary'] : null,
            ! empty($metadata['chapters']) ? "Chapters:\n- " . implode("\n- ", (array) $metadata['chapters']) : null,
            ! empty($metadata['action_items']) ? "Action items:\n- " . implode("\n- ", (array) $metadata['action_items']) : null,
            ucfirst($kind->bodyLabel()) . ":\n" . $this->signal->content,
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function instruction(Message $note): string
    {
        $isLead = $this->target->record instanceof Lead;
        $record = $isLead ? 'lead' : 'organization (customer account)';
        $noun = $this->signal->kind->noun();
        $body = $this->signal->kind->bodyLabel();
        $noteTool = $isLead ? 'add_lead_note' : 'add_organization_note';

        return sprintf(
            'A new %1$s was just routed to the %3$s "%4$s" (%5$s_id %6$d). Its full %2$s is message_id %7$d on that '
            . "record's activity thread.\n\n"
            . "1. Read the whole %2\$s with read_message_content(message_id: %7\$d), paging until has_more is false.\n"
            . '2. Write ONE note with %8$s: what was discussed, decisions, objections or risks, and the next steps '
            . "with an owner and a date when the %1\$s gave one.\n"
            . '%9$s'
            . 'Do not contact the customer from this turn. If a follow-up message is needed, draft it inside the '
            . 'note for a person to send.',
            $noun,
            $body,
            $record,
            $this->target->label(),
            $this->target->type->value,
            $this->target->id(),
            (int) $note->getId(),
            $noteTool,
            $isLead
                ? "3. Update the lead (status, description, fields) only where the {$noun} clearly changed it.\n"
                : "3. This turn is about the account, not one of your projects; don't create or move project "
                    . "work unless the {$noun} asked for it.\n",
        );
    }
}
