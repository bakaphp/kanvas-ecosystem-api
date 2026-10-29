<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Baka\Support\Arr;
use Baka\Validations\EmailDomain;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\ConsentGuard;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;

/**
 * The recipient rules every tool that emails a CRM person shares: the primary address comes from the
 * person's own contacts, and CC accepts only contacts of the people the host names as related
 * (`resolveCcRecipients()`'s candidates) or an address on a domain the app's admin allowlisted. The
 * model never chooses a destination — see "Destination safety" in Agents/CLAUDE.md.
 *
 * Requires HasKanvasContext on the host (`$this->app`, `$this->company`, `hasTenantContext()`,
 * `contextAgent()`), and `$name` for the retry hint.
 */
trait SendsEmailToPeople
{
    use HasConversationHuman;

    /**
     * Which email slot we write to first when a person has several. Membership of the set lives on
     * Contact::EMAIL_TYPES; this only encodes the send preference.
     */
    private const array EMAIL_TYPE_PRIORITY = [
        ContactTypeEnum::PRIMARY_EMAIL->value,
        ContactTypeEnum::EMAIL->value,
        ContactTypeEnum::SECONDARY_EMAIL->value,
    ];

    protected function signatureOwner(): ?Users
    {
        if (! ($this->contextAgent()?->conversesWithUser() ?? false)) {
            return null;
        }

        // Never infer the sender from the record's owner or the shared bot context user.
        return $this->conversationHuman;
    }

    /**
     * deliverable() is is_opt_out=0 AND not a permanent failure — so a hard-bounced or invalid address
     * (flagged by the Mailgun bounce webhook / email validation) is never emailed again. Soft bounces
     * stay eligible.
     */
    protected function deliverableEmailContact(?People $person): ?Contact
    {
        return $person?->contacts()
            ->whereIn('contacts_types_id', Contact::EMAIL_TYPES)
            ->deliverable()
            ->orderByRaw('FIELD(contacts_types_id, ' . implode(',', self::EMAIL_TYPE_PRIORITY) . ')')
            ->first();
    }

    /**
     * The three dead ends need different behaviour from the model: a bad address it can replace, an
     * opt-out it must never work around, and a missing address it should simply ask for.
     */
    protected function noDeliverableEmailMessage(?People $person, string $saveWith): string
    {
        $contacts = $person?->contacts()->whereIn('contacts_types_id', Contact::EMAIL_TYPES)->get();

        if ($contacts === null || $contacts->isEmpty()) {
            return 'There is no email address on file. Ask the person for the email address they want it sent to, '
                . "save it with {$saveWith}, then retry {$this->name}.";
        }

        if ($contacts->contains(fn (Contact $contact): bool => ! $contact->isOptedOut())) {
            return 'The email address on file bounced or is invalid, so we cannot write to it. '
                . "Ask the person to confirm a working email address, save it with {$saveWith}, then retry {$this->name}.";
        }

        return 'This person opted out of email. Do not email them and do not ask them for another address. '
            . 'Offer to help here in the conversation instead.';
    }

    /**
     * Split the requested CC addresses into the ones we will actually copy and the ones we drop. An
     * address is accepted only if it matches — case-insensitively — a deliverable email contact of one
     * of `$candidatePeopleIds`, or sits on a domain the app's admin allowlisted; the primary recipient
     * and duplicates are removed. This is the anti-exfiltration guarantee: CC can widen delivery to
     * known contacts or admin-approved domains, never to an arbitrary address the model was told to add.
     *
     * @param array<int, int> $candidatePeopleIds
     * @return array{accepted: array<int, string>, rejected: array<int, string>}
     */
    protected function resolveCcRecipients(array $candidatePeopleIds, ?string $cc, string $primaryEmail): array
    {
        $requested = preg_split('/[,;\s]+/', trim((string) $cc)) ?: [];
        $requested = array_values(array_filter($requested, static fn (string $part): bool => $part !== ''));

        if ($requested === []) {
            return ['accepted' => [], 'rejected' => []];
        }

        $allowed = $this->allowedCcContacts($candidatePeopleIds);
        $allowedDomains = $this->allowedCcDomains();
        $primaryNormalized = strtolower(trim($primaryEmail));

        $accepted = [];
        $rejected = [];
        $seen = [];

        foreach ($requested as $email) {
            $normalized = strtolower(trim($email));
            if ($normalized === '' || $normalized === $primaryNormalized || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            if (isset($allowed[$normalized])) {
                $accepted[] = $allowed[$normalized];
            } elseif ($this->isAllowedDomainCc($normalized, $allowedDomains)) {
                $accepted[] = trim($email);
            } else {
                $rejected[] = $email;
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * An agent told only "not copied" emailed the rejected address separately through
     * send_email_to_user, so the refusal rules that out in so many words.
     *
     * @param array<int, string> $rejected
     */
    protected function rejectedCcNote(array $rejected, string $onFile, string $howToInclude): string
    {
        if ($rejected === []) {
            return '';
        }

        return " These CC addresses were NOT copied — they are not on file for {$onFile}, and are not on an "
            . 'allowed CC domain (or that person opted out of contact): '
            . implode(', ', $rejected)
            . '. Do NOT send them a copy any other way (send_email_to_user, a second email, a forward) — '
            . 'tell the person plainly they were not copied and why. '
            . "To include them, {$howToInclude}.";
    }

    /**
     * @return list<string>
     */
    private function allowedCcDomains(): array
    {
        if (! $this->hasTenantContext()) {
            return [];
        }

        return Arr::fromSettingList($this->app->get(ConfigurationEnum::AGENT_EMAIL_CC_ALLOWED_DOMAINS->value));
    }

    /**
     * An allowlisted-domain address is not a related contact, but it may still be someone in the CRM
     * who asked not to be contacted or whose address bounced — a stop request is person-wide, so the
     * allowlist does not override it.
     *
     * @param list<string> $allowedDomains
     */
    private function isAllowedDomainCc(string $email, array $allowedDomains): bool
    {
        if ($allowedDomains === []
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! EmailDomain::isInDomains($email, $allowedDomains)) {
            return false;
        }

        return ! Contact::query()
            ->whereIn('contacts_types_id', Contact::EMAIL_TYPES)
            ->where('value', $email)
            ->notDeleted()
            ->whereHas(
                'people',
                fn (Builder $query): Builder => $query->fromCompany($this->company)->fromApp($this->app)->notDeleted()
            )
            ->with('people')
            ->get()
            ->contains(
                fn (Contact $contact): bool => ! $contact->isDeliverable() || ConsentGuard::isPersonDoNotContact($contact->people)
            );
    }

    /**
     * Deliverable email addresses of the candidate people, keyed by lowercase value → the stored
     * (canonical-cased) value we actually send to.
     *
     * Every candidate is re-checked against this app + company before its addresses count. The record
     * itself is tenant-scoped, but related people hang off it through raw FKs that other write paths
     * set without a tenant check (connector imports, lead merges, the addLeadParticipant mutation), so
     * a row pointing at another tenant's person must not become a reachable CC target. No tenant
     * context at all means no CC at all — fail closed.
     *
     * @param array<int, int> $candidatePeopleIds
     * @return array<string, string>
     */
    private function allowedCcContacts(array $candidatePeopleIds): array
    {
        if (! $this->hasTenantContext() || $candidatePeopleIds === []) {
            return [];
        }

        $peopleIds = People::query()
            ->fromCompany($this->company)
            ->fromApp($this->app)
            ->notDeleted()
            ->whereIn('id', $candidatePeopleIds)
            ->pluck('id')
            ->all();

        if ($peopleIds === []) {
            return [];
        }

        $contacts = Contact::query()
            ->whereIn('peoples_id', $peopleIds)
            ->whereIn('contacts_types_id', Contact::EMAIL_TYPES)
            ->notDeleted()
            ->deliverable()
            ->get();

        $allowed = [];
        foreach ($contacts as $contact) {
            $allowed[strtolower(trim($contact->value))] = $contact->value;
        }

        return $allowed;
    }
}
