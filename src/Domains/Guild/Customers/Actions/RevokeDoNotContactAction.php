<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\DataTransferObject\ConsentOutcome;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * START / UNSTOP — the inverse of ApplyDoNotContactAction, but deliberately not symmetric.
 *
 * Opting back in re-enables only the address the request came from. A blanket opt-in would also
 * clear opt-outs this person never revoked here — a hard bounce, or a `doNotEmail` pushed in by a
 * DMS sync — and re-open channels they never asked to re-open. Wide to silence, narrow to resume.
 */
final class RevokeDoNotContactAction
{
    public function __construct(
        private readonly People $people,
        private readonly string $sourceChannel,
        private readonly ?Lead $lead = null,
        private readonly ?string $contactValue = null,
    ) {
    }

    public function execute(): ConsentOutcome
    {
        if (! (bool) $this->people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)) {
            return new ConsentOutcome(signal: ConsentSignalEnum::START);
        }

        [$contactsOptedIn, $leadsCleared] = DB::connection('crm')->transaction(
            fn (): array => $this->clear(),
        );

        return new ConsentOutcome(
            signal: ConsentSignalEnum::START,
            applied: true,
            contactsOptedOut: $contactsOptedIn,
            leadsFlagged: $leadsCleared,
        );
    }

    /**
     * @return array{0: int, 1: int} contacts opted back in, leads cleared
     */
    private function clear(): array
    {
        $this->people->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, 0);

        $contactsOptedIn = $this->optInSourceContact();

        $leadsCleared = 0;

        foreach ($this->people->leads()->fromApp($this->people->app)->get() as $lead) {
            if (! (bool) $lead->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value)) {
                continue;
            }

            $lead->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, 0);

            // Drop the manual pin rather than guessing a mode: resolveAiMode then falls back to the
            // lead-type / company default, which is where the lead was before it was silenced.
            $lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, false);

            $leadsCleared++;
        }

        return [$contactsOptedIn, $leadsCleared];
    }

    private function optInSourceContact(): int
    {
        if ($this->contactValue === null) {
            return 0;
        }

        return $this->people->contacts()
            ->where('is_opt_out', 1)
            ->get()
            ->filter(fn (Contact $contact): bool => Contact::normalizeValue(
                $contact->value,
                (int) $contact->contacts_types_id,
            ) === Contact::normalizeValue(
                $this->contactValue,
                (int) $contact->contacts_types_id,
            ))
            ->each(fn (Contact $contact) => $contact->optIn())
            ->count();
    }
}
