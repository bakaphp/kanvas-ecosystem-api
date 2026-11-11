<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\UpsertsMirroredRows;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CustomerMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Connectors\BrushCrazy\Support\StudioCredits;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\Enums\ContactCustomFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;
use stdClass;

/**
 * Writes one BrushCrazy user into one studio company as a People. The bulk import, the mirror
 * webhook and the registration/sale syncs (for buyers) all call it, so they cannot drift.
 *
 * A customer who bought in two studios becomes two People, one per company — People is
 * company-scoped, and a registration has to find its participant in the event's own company.
 * BRUSHCRAZY_USER_ID on each copy is what lets them be merged later.
 *
 * Resolution is by BRUSHCRAZY_USER_ID. The one exception is adoption: a People the new site
 * created for the same email, with no BRUSHCRAZY_USER_ID yet, is stamped and updated instead of
 * getting a twin — the site finds its buyer by email and refuses to book when two People match.
 */
class SyncCustomerFromBrushCrazyAction
{
    use UpsertsMirroredRows;

    private const int LOCK_SECONDS = 30;

    private const int LOCK_WAIT_SECONDS = 10;

    private const string RETIRED_CONTACTS = 'retired';

    protected IdMapRepository $idMaps;

    /** @var array<int, int>|null */
    protected ?array $studioCredits = null;

    protected ?int $peopleTypeId = null;

    /** @var array<int, int> ContactTypeEnum value => contacts_types.id */
    protected array $contactTypeIds = [];

    /** @var array<string, int|null> normalized email => adoptable People id, null when there is none */
    protected array $adoptable = [];

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?IdMapRepository $idMaps = null,
        protected ?StudioCredits $credits = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
    }

    /**
     * @param  array<int, int>  $bcUserIds
     */
    public function preload(array $bcUserIds): void
    {
        $this->preloadMirroredIds(People::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, $bcUserIds);
        $this->preloadMirroredFields(People::class, CustomFieldEnum::BRUSHCRAZY_USER_ID);
        $this->studioCredits = $this->credits()->forUsers($this->context->bcStudioId, $bcUserIds);
    }

    /**
     * preload() plus one adoption query for the chunk's users that have no People yet, instead of
     * one per new row on a first import.
     *
     * @param  array<int, stdClass>  $rows
     */
    public function preloadRows(array $rows): void
    {
        $this->preload(array_map(fn (stdClass $row) => (int) $row->id, $rows));

        $mapped = $this->mirroredIdMaps[People::class . '|' . CustomFieldEnum::BRUSHCRAZY_USER_ID->value] ?? [];
        $emails = [];

        foreach ($rows as $row) {
            $email = Str::lower(trim((string) ($row->email ?? '')));

            if ($email !== '' && ! isset($mapped[(string) $row->id]) && ($row->deleted_at ?? null) === null) {
                $emails[] = $email;
            }
        }

        $this->adoptable = $this->findAdoptable(array_values(array_unique($emails)));
    }

    public function fromRow(stdClass $row): People
    {
        $bcUserId = (int) $row->id;
        $mapped = CustomerMapper::map($row);

        // The webhook, the pull and a registration creating its buyer can reach the same user at
        // once; without the lock each would see "no People yet" and create one.
        $people = $this->lockFor($bcUserId)->block(
            self::LOCK_WAIT_SECONDS,
            fn () => $this->upsertPeople($bcUserId, $mapped)
        );

        $this->syncContacts($people, [
            ContactTypeEnum::EMAIL->value => $mapped['email'],
            ContactTypeEnum::PHONE->value => $mapped['phone'],
        ]);

        if ($mapped['address'] !== null) {
            $people->replaceDefaultAddress(new AddressData(...$mapped['address']));
        }

        $this->writeMirroredFields(
            $people,
            $mapped['custom_fields'] + [
                CustomFieldEnum::BRUSHCRAZY_STUDIO_CREDIT_CENTS->value => $this->studioCreditCents($people, $bcUserId),
            ]
        );

        return $people;
    }

    /**
     * Null when the user never had credit in this studio, so ~96k People don't each get a zero; a
     * user who spent their credit down still gets 0, which is what replaces the old balance.
     *
     * A user whose every credit row was reverted drops out of the source query altogether, so a
     * balance already stored is zeroed rather than left standing.
     */
    protected function studioCreditCents(People $people, int $bcUserId): ?int
    {
        $cents = $this->studioCredits !== null
            ? $this->studioCredits[$bcUserId] ?? null
            : $this->credits()->forUsers($this->context->bcStudioId, [$bcUserId])[$bcUserId] ?? null;

        if ($cents !== null) {
            return $cents;
        }

        return $this->storedMirroredField($people, CustomFieldEnum::BRUSHCRAZY_STUDIO_CREDIT_CENTS->value) !== null ? 0 : null;
    }

    protected function credits(): StudioCredits
    {
        return $this->credits ??= new StudioCredits(new Client($this->app));
    }

    protected function lockFor(int $bcUserId): Lock
    {
        return Cache::lock(
            'brushcrazy-customer:' . $this->app->getId() . ':' . $this->context->companyId() . ':' . $bcUserId,
            self::LOCK_SECONDS
        );
    }

    /**
     * Observers stay off: PeopleObserver queues a duplicate check and creates a notes channel per
     * row, ~100k of each on a full import. The notes channel is created lazily on the first note
     * (RecordPeopleNoteAction), and within one company the source email is unique, so the
     * duplicate check has nothing to find. The search index is refreshed by the caller in bulk.
     *
     * The source created_at is kept on insert only, and only when the source has one.
     * BRUSHCRAZY_USER_ID is written here, inside the caller's lock, so the next writer finds it.
     *
     * A user deleted at the source never adopts: it would hide the site's live customer.
     *
     * @param  array<string, mixed>  $mapped  CustomerMapper::map()
     */
    protected function upsertPeople(int $bcUserId, array $mapped): People
    {
        $attributes = $mapped['people'];
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $id = $this->mirroredId(People::class, CustomFieldEnum::BRUSHCRAZY_USER_ID, $bcUserId);
        $adopted = false;

        if ($id === null && ! $attributes['is_deleted'] && $mapped['email'] !== null) {
            $id = $this->adoptablePeopleId($mapped['email']);
            $adopted = $id !== null;
        }

        /** @var People $people */
        $people = $this->upsertMirrored(
            People::class,
            $id !== null ? ['id' => $id] : null,
            $attributes,
            [
                'uuid' => Str::uuid()->toString(),
                'users_id' => $this->user->getId(),
                'people_types_id' => $this->peopleTypeId(),
            ] + ($createdAt !== null ? ['created_at' => $createdAt] : []),
        );

        $this->rememberMirroredId(
            People::class,
            CustomFieldEnum::BRUSHCRAZY_USER_ID,
            $bcUserId,
            (int) $people->getId()
        );

        if ($people->wasRecentlyCreated || $adopted) {
            $this->writeMirroredFields($people, [CustomFieldEnum::BRUSHCRAZY_USER_ID->value => $bcUserId]);
        }

        return $people;
    }

    /**
     * Taken once: a stamped People is no longer adoptable.
     */
    protected function adoptablePeopleId(string $email): ?int
    {
        $email = Str::lower(trim($email));

        if (! array_key_exists($email, $this->adoptable)) {
            $this->adoptable += $this->findAdoptable([$email]);
        }

        $id = $this->adoptable[$email] ?? null;
        unset($this->adoptable[$email]);

        return $id;
    }

    /**
     * Live People of this app and company holding one of these emails on a live email contact,
     * minus those already carrying a BRUSHCRAZY_USER_ID. Several matches are a duplicate the site
     * itself would refuse to book; the oldest is adopted, deterministically, and it is logged.
     *
     * @param  array<int, string>  $emails  lowercased and trimmed
     * @return array<string, int|null>
     */
    protected function findAdoptable(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $found = array_fill_keys($emails, null);

        $candidates = People::withTrashed()
            ->join('peoples_contacts', 'peoples_contacts.peoples_id', '=', 'peoples.id')
            ->where('peoples.apps_id', $this->app->getId())
            ->where('peoples.companies_id', $this->context->companyId())
            ->where('peoples.is_deleted', 0)
            ->where('peoples_contacts.is_deleted', 0)
            ->whereIn('peoples_contacts.contacts_types_id', Contact::EMAIL_TYPES)
            ->whereIn('peoples_contacts.value', $emails)
            ->orderBy('peoples.id')
            ->toBase()
            ->get(['peoples.id', 'peoples_contacts.value']);

        $stamped = $this->idMaps->loadReverse(
            $this->context->companyId(),
            People::class,
            CustomFieldEnum::BRUSHCRAZY_USER_ID,
            $candidates->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );

        $byEmail = [];

        foreach ($candidates as $candidate) {
            $email = Str::lower(trim((string) $candidate->value));

            if (array_key_exists($email, $found) && ! isset($stamped[(int) $candidate->id])) {
                $byEmail[$email][(int) $candidate->id] = true;
            }
        }

        foreach ($byEmail as $email => $ids) {
            $ids = array_keys($ids);
            sort($ids);

            if (count($ids) > 1) {
                Log::warning('BrushCrazy customer sync: several People share one email; adopting the oldest', [
                    'companies_id' => $this->context->companyId(),
                    'peoples_ids' => $ids,
                ]);
            }

            $found[$email] = $ids[0];
        }

        return $found;
    }

    /**
     * One contact per type belongs to the mirror — the one it created or adopted, remembered in
     * BRUSHCRAZY_MIRRORED_CONTACTS. A changed source value rewrites that row, so an old address does
     * not linger beside the new one and the row keeps its opt-out; contacts added in Kanvas are never
     * touched.
     *
     * `is_opt_out` is never written — it is consent, not imported data. Observers are off, so the
     * value is stored the way ContactObserver stores it (digits-only phones, country code kept) and
     * matched through Contact::normalizeValue().
     *
     * withTrashed(): a contact someone removed in Kanvas is still matched, so it stays removed
     * instead of reappearing on the next sync. Contacts the mirror itself removed are listed under
     * `retired` and come back when their value does.
     *
     * @param  array<int, string|null>  $values  ContactTypeEnum value => source value
     */
    protected function syncContacts(People $people, array $values): void
    {
        $key = CustomFieldEnum::BRUSHCRAZY_MIRRORED_CONTACTS->value;
        $stored = $people->get($key);
        $stored = is_array($stored) ? $stored : [];
        $retired = array_map('intval', (array) ($stored[self::RETIRED_CONTACTS] ?? []));
        unset($stored[self::RETIRED_CONTACTS]);
        $managed = array_map('intval', $stored);
        $before = $this->mirroredContacts($managed, $retired);
        $contacts = Contact::withTrashed()->where('peoples_id', $people->getId())->get();

        foreach ($values as $typeValue => $value) {
            $typeId = $this->contactTypeId(ContactTypeEnum::from($typeValue));
            $normalized = $value !== null ? Contact::normalizeValue($value, $typeId) : '';
            $current = isset($managed[$typeValue]) ? $contacts->firstWhere('id', $managed[$typeValue]) : null;
            $live = $current !== null && ! $current->is_deleted ? $current : null;

            if ($normalized === '') {
                if ($live !== null) {
                    $this->writeContact($live, ['is_deleted' => true]);
                    $retired[] = (int) $live->getKey();
                }

                unset($managed[$typeValue]);

                continue;
            }

            $match = $this->matchContact($contacts, $typeId, $normalized);
            $storedValue = Contact::isPhoneType($typeId) ? Contact::cleanPhone((string) $value) : $normalized;

            if ($match !== null) {
                if ($live !== null && (int) $live->getKey() !== (int) $match->getKey()) {
                    $this->writeContact($live, ['is_deleted' => true]);
                    $retired[] = (int) $live->getKey();
                }

                if ($match->is_deleted && in_array((int) $match->getKey(), $retired, true)) {
                    $this->writeContact($match, ['is_deleted' => false]);
                }

                $retired = array_values(array_diff($retired, [(int) $match->getKey()]));
                $managed[$typeValue] = (int) $match->getKey();

                continue;
            }

            if ($live !== null) {
                $this->rewriteContact($live, $storedValue);
                $managed[$typeValue] = (int) $live->getKey();

                continue;
            }

            $contact = new Contact([
                'peoples_id' => $people->getId(),
                'contacts_types_id' => $typeId,
            ]);
            $this->writeContact($contact, ['value' => $storedValue]);
            $contacts->push($contact);
            $managed[$typeValue] = (int) $contact->getKey();
        }

        $after = $this->mirroredContacts($managed, $retired);

        if ($after === $before) {
            return;
        }

        if ($after === []) {
            $people->del($key);

            return;
        }

        $people->set($key, $after);
    }

    /**
     * @param  array<int, int>  $managed
     * @param  array<int, int>  $retired
     * @return array<int|string, int|array<int, int>>
     */
    protected function mirroredContacts(array $managed, array $retired): array
    {
        ksort($managed);
        $retired = array_values(array_unique($retired));
        sort($retired);

        return $managed + ($retired !== [] ? [self::RETIRED_CONTACTS => $retired] : []);
    }

    /**
     * A live match wins over a deleted one with the same value.
     *
     * @param  Collection<int, Contact>  $contacts
     */
    protected function matchContact(Collection $contacts, int $typeId, string $normalized): ?Contact
    {
        return $contacts->sortBy(fn (Contact $contact) => (int) $contact->is_deleted)->first(
            fn (Contact $contact) => (int) $contact->contacts_types_id === $typeId
                && Contact::normalizeValue((string) $contact->value, $typeId) === $normalized
        );
    }

    /**
     * A new address on the same row: what was learned about the old one — a bounce, a validation
     * result, a landline lookup — does not carry over. The opt-out does, by design.
     */
    protected function rewriteContact(Contact $contact, string $value): void
    {
        $this->writeContact($contact, [
            'value' => $value,
            'validation_status' => ContactValidationStatusEnum::VALID,
            'bounced_at' => null,
        ]);

        $contact->del(ContactCustomFieldEnum::IS_LANDLINE->value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function writeContact(Contact $contact, array $attributes): void
    {
        Contact::withoutEvents(fn () => $contact->forceFill($attributes)->saveQuietly());
    }

    protected function peopleTypeId(): int
    {
        return $this->peopleTypeId ??= $this->idMaps->lookup(
            $this->context->companyId(),
            PeopleType::class,
            'people-type:customer'
        );
    }

    protected function contactTypeId(ContactTypeEnum $type): int
    {
        return $this->contactTypeIds[$type->value] ??= (int) ContactType::getByName($type->getName())->getId();
    }
}
