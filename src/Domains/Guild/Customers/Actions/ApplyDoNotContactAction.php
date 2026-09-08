<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\DataTransferObject\ConsentOutcome;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ConsentMatchEnum;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Guild\Customers\Models\People;
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

        [$contactsOptedOut, $leadsFlagged] = DB::connection('crm')->transaction(
            fn (): array => $this->flag(),
        );

        // Notes, the compliance handoff and the ledger write all live outside the transaction and
        // are individually best-effort: they reach other connections and other services, and none
        // of them failing is a reason to leave the person still contactable.
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
     * @return array{0: int, 1: int} contacts opted out, leads flagged
     */
    private function flag(): array
    {
        $contactsOptedOut = $this->people->contacts()
            ->where('is_opt_out', 0)
            ->update(['is_opt_out' => 1]);

        $this->stamp($this->people);

        $leadsFlagged = 0;

        // The person-level flag above is what protects leads created AFTER this point; this loop
        // covers the ones that already exist, since every outbound guard reads the lead.
        foreach ($this->people->leads()->fromApp($this->people->app)->get() as $lead) {
            if ($this->flagLead($lead)) {
                $leadsFlagged++;
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

        $reason = Str::trimToNull($this->reason);

        if ($reason !== null) {
            $entity->set(ConsentConfigurationEnum::DO_NOT_CONTACT_REASON->value, $reason);
        }
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
        $reason = Str::trimToNull($this->reason);

        return sprintf(
            'Do-not-contact honored from %s%s. %d contact(s) opted out, %d lead(s) flagged. '
                . 'Automated messaging is disabled across SMS, WhatsApp and email.%s',
            $this->sourceChannel,
            $reason !== null ? sprintf(' — "%s"', $reason) : '',
            $contactsOptedOut,
            $leadsFlagged,
            // An inferred opt-out says so in the note a human reads, so a wrong call is findable and
            // reversible rather than quietly standing forever.
            $this->match->warrantsReview()
                ? sprintf(' Inferred from %s rather than an explicit request — review if this looks wrong.', $this->match->value)
                : '',
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
                    'reason' => Str::trimToNull($this->reason),
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
