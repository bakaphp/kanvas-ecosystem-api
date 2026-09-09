<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Customers\DataTransferObject\ConsentOutcome;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\ConsentKeywordService;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Actions\HandOffAction;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\HandOffTypeEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Throwable;

/**
 * Person-wide do-not-contact: the single place that honors a stop request, whichever channel or
 * caller detected it.
 *
 * Scope is deliberately the whole person, not the channel they used and not the lead they used.
 * The FCC's revoke-all rule (one channel's revocation binding every channel) lands 2027-01-31, and
 * WhatsApp's Business Policy already requires honoring requests made "on or off WhatsApp" — so a
 * STOP over SMS silences email and WhatsApp too. `People` is apps_id + companies_id scoped, so this
 * stays tenant-bounded by construction: opting out at one dealer does not opt out at another.
 */
final class ApplyDoNotContactAction
{
    public function __construct(
        private readonly People $people,
        private readonly string $sourceChannel,
        private readonly ?Lead $lead = null,
        private readonly ?string $reason = null,
        private readonly ConsentMatchEnum $match = ConsentMatchEnum::EXACT,
    ) {
    }

    public function execute(): ConsentOutcome
    {
        if ((bool) $this->people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)) {
            return new ConsentOutcome(
                signal: ConsentSignalEnum::STOP,
                alreadyOptedOut: true,
                match: $this->match,
            );
        }

        [$contactsOptedOut, $leadsFlagged] = $this->flag();

        // The audit trail runs after the flags and is individually best-effort: it reaches other
        // services, and none of them failing is a reason to leave the person still contactable.
        $this->recordNotes($contactsOptedOut, $leadsFlagged);
        $this->notifyTeam();
        $this->emitLedgerEvent($contactsOptedOut, $leadsFlagged);

        return new ConsentOutcome(
            signal: ConsentSignalEnum::STOP,
            applied: true,
            contactsOptedOut: $contactsOptedOut,
            leadsFlagged: $leadsFlagged,
            match: $this->match,
        );
    }

    /**
     * Not transactional, deliberately. The person and lead flags are custom fields, which live on
     * the `ecosystem` connection, while the contact opt-outs live on `crm` — so no single
     * transaction can cover both. Wrapping this in one only ever protected the contact update while
     * implying an atomicity the flags never had: a throw mid-loop rolled back the opt-outs and left
     * the flags standing.
     *
     * Ordered instead. The People stamp goes first because it is the flag every outbound guard
     * reads and the only one that covers leads created after this point, so a failure anywhere
     * below it still leaves the person silenced — the safe direction to fail.
     *
     * @return array{0: int, 1: int} contacts opted out, leads flagged
     */
    private function flag(): array
    {
        $this->stamp($this->people);

        $contactsOptedOut = $this->people->contacts()
            ->where('is_opt_out', 0)
            ->update(['is_opt_out' => 1]);

        $leadsFlagged = 0;

        // The person-level flag above is what protects leads created AFTER this point; this loop
        // covers the ones that already exist, since every outbound guard reads the lead.
        foreach ($this->people->leads()->fromApp($this->people->app)->get() as $lead) {
            try {
                if ($this->flagLead($lead)) {
                    $leadsFlagged++;
                }
            } catch (Throwable $e) {
                // One lead failing must not leave the remaining ones contactable.
                report($e);
            }
        }

        return [$contactsOptedOut, $leadsFlagged];
    }

    private function flagLead(Lead $lead): bool
    {
        if ((bool) $lead->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)) {
            return false;
        }

        $this->stamp($lead);

        // AI_MODE alone does not hold: resolveAiMode() lets the lead-type config outrank the stored
        // mode unless the manual flag is set, so a muted lead would quietly start replying again.
        $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::IDLE->value);
        $lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);

        return true;
    }

    private function stamp(People|Lead $entity): void
    {
        $entity->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, 1);
        $entity->set(ConsentConfigurationEnum::DO_NOT_CONTACT_AT->value, Carbon::now()->toIso8601String());
        $entity->set(ConsentConfigurationEnum::DO_NOT_CONTACT_SOURCE->value, $this->sourceChannel);
        $entity->set(ConsentConfigurationEnum::DO_NOT_CONTACT_MATCH->value, $this->match->value);

        $reason = $this->storedReason();

        if ($reason !== null) {
            $entity->set(ConsentConfigurationEnum::DO_NOT_CONTACT_REASON->value, $reason);
        }
    }

    /**
     * The reason as it goes into the audit trail — a custom field on the person and on every one of
     * their leads, plus a lead note. Clamped here rather than only at the inbound boundary because
     * every caller writes to those same places; `stop_contact` passes whatever the model wrote.
     */
    private function storedReason(): ?string
    {
        return Str::trimToNull(Str::limit((string) $this->reason, ConsentKeywordService::MAX_REASON_LENGTH));
    }

    private function recordNotes(int $contactsOptedOut, int $leadsFlagged): void
    {
        $body = $this->noteBody($contactsOptedOut, $leadsFlagged);

        try {
            new RecordPeopleNoteAction($this->people)->execute($body, 'opt-out');

            if ($this->lead !== null) {
                new RecordLeadNoteAction($this->lead)->execute($body, 'opt-out');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function noteBody(int $contactsOptedOut, int $leadsFlagged): string
    {
        $reason = $this->storedReason();

        return sprintf(
            'Do-not-contact honored from %s%s. %d contact(s) opted out, %d lead(s) flagged. '
                . 'Automated messaging is disabled across SMS, WhatsApp and email.%s',
            $this->sourceChannel,
            $reason !== null ? sprintf(' — "%s"', $reason) : '',
            $contactsOptedOut,
            $leadsFlagged,
            $this->match->reviewNote() ?? '',
        );
    }

    private function notifyTeam(): void
    {
        if ($this->lead === null) {
            return;
        }

        try {
            new HandOffAction(
                lead: $this->lead,
                app: $this->lead->app,
                params: ['handoff_type' => HandOffTypeEnum::COMPLIANCE_INTERNAL->value],
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function emitLedgerEvent(int $contactsOptedOut, int $leadsFlagged): void
    {
        if ($this->lead === null) {
            return;
        }

        try {
            $this->lead->emitLedgerEvent(
                'lead.do_not_contact',
                payload: [
                    'source_channel' => $this->sourceChannel,
                    'match' => $this->match->value,
                    'reason' => $this->storedReason(),
                    'people_id' => $this->people->getId(),
                    'contacts_opted_out' => $contactsOptedOut,
                    'leads_flagged' => $leadsFlagged,
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
