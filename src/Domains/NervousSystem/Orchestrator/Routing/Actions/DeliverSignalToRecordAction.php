<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Kanvas\Filesystem\Models\Filesystem;
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
use Kanvas\NervousSystem\Orchestrator\Signals\Actions\StoreSignalFileAction;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\Social\Messages\Models\Message;

/**
 * Hands a routed signal to a lead (Sales Manager) or an organization (PM): it is written as an
 * internal note on the record, and the agent is woken with a short instruction pointing at it. The
 * wording follows the signal's kind (meeting, email, ...), so nothing here is specific to transcripts.
 * The `internal` verb keeps the note out of a customer-facing agent's history.
 *
 * A signal stored as a file leaves a digest in the note and the full text in the attachment, which
 * the agent pages through with read_file. Without a file the note carries the full text and the agent
 * reads it with read_message_content.
 *
 * Nothing is written when the company has no agent for the role: a signal nobody will read is
 * left in the webhook request and the routing log instead.
 */
class DeliverSignalToRecordAction
{
    private const int LOCK_SECONDS = 30;
    private const int LOCK_WAIT_SECONDS = 10;

    private ?Filesystem $file = null;

    public function __construct(
        private readonly RoutingCandidate $target,
        private readonly InboundSignal $signal,
        private readonly ?StoreSignalFileAction $archive = null
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
        $this->file = $this->archive?->execute();

        return $recorder->execute(
            body: $this->file !== null ? $this->signal->digest($this->file) : $this->signal->document(),
            tag: $kind->noun() . '-' . $kind->bodyLabel(),
            messageTypeVerb: LeadMessageTypeEnum::INTERNAL->value,
            slug: $slug,
            files: $this->file !== null ? [$this->file] : [],
        );
    }

    private function instruction(Message $note): string
    {
        $isLead = $this->target->record instanceof Lead;
        $record = $isLead ? 'lead' : 'organization (customer account)';
        $noun = $this->signal->kind->noun();
        $body = $this->signal->kind->bodyLabel();
        $noteTool = $isLead ? 'add_lead_note' : 'add_organization_note';

        [$where, $read] = $this->file !== null
            ? [
                sprintf(
                    'Its summary is message_id %d on that record\'s activity thread, and its full %s is the file '
                    . '"%s" attached to it (filesystem_id %d).',
                    (int) $note->getId(),
                    $body,
                    $this->file->name,
                    $this->file->getId()
                ),
                sprintf('read_file(filesystem_id: %d)', $this->file->getId()),
            ]
            : [
                sprintf('Its full %s is message_id %d on that record\'s activity thread.', $body, (int) $note->getId()),
                sprintf('read_message_content(message_id: %d)', (int) $note->getId()),
            ];

        return sprintf(
            'A new %1$s was just routed to the %3$s "%4$s" (%5$s_id %6$d). %7$s' . "\n\n"
            . "1. Read the whole %2\$s with %8\$s, paging until has_more is false.\n"
            . '2. Write ONE note with %9$s: what was discussed, decisions, objections or risks, and the next steps '
            . "with an owner and a date when the %1\$s gave one.\n"
            . '%10$s'
            . 'Do not contact the customer from this turn. If a follow-up message is needed, draft it inside the '
            . 'note for a person to send.',
            $noun,
            $body,
            $record,
            $this->target->label(),
            $this->target->type->value,
            $this->target->id(),
            $where,
            $read,
            $noteTool,
            $isLead
                ? "3. Update the lead (status, description, fields) only where the {$noun} clearly changed it.\n"
                : "3. This turn is about the account, not one of your projects; don't create or move project "
                    . "work unless the {$noun} asked for it.\n",
        );
    }
}
