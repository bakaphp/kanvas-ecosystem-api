<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Baka\Support\Str;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\DataTransferObject\Contact as ContactData;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Guild\Organizations\Models\Organization as OrganizationModel;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Spatie\LaravelData\DataCollection;

/**
 * Fetches a person's Apollo match and writes the enrichment back to the People
 * record (job, employment history, LinkedIn, email, location, organization).
 *
 * This is the runtime-agnostic core of the enrichment. ScreeningPeopleActivity
 * keeps the rate-limit / recently-screened gating and delegates the actual
 * fetch + write here, so the same logic can run directly from a command without
 * a workflow/activity in the loop.
 */
class EnrichPeopleFromApolloAction
{
    public function __construct(
        protected People $people,
        protected Apps $app
    ) {
    }

    public function execute(): array
    {
        try {
            $peopleData = new ScreeningAction($this->people, $this->app)->execute();
        } catch (GuzzleException $e) {
            return $this->response('failed', $e->getMessage());
        }

        if (empty($peopleData)) {
            self::recordNoDataAttempt($this->people);

            return $this->response('failed', 'No Apollo match found');
        }

        // A free / credit-exhausted Apollo key still returns a bare match (just the
        // name + the email we sent), but no title, LinkedIn, employment history,
        // location or phone. Writing that back gains nothing and — worse — would let
        // the empty payload clobber existing data. Bail out without touching anything.
        if (! $this->hasMeaningfulEnrichment($peopleData)) {
            self::recordNoDataAttempt($this->people);

            return $this->response('no_data', 'Apollo matched the person but returned no enrichment data (free/credit-limited key)');
        }

        $this->applyEnrichmentData($peopleData);

        return $this->response('success', 'People screened successfully', $peopleData);
    }

    /**
     * Stamp "we tried and Apollo had nothing for this person". Only genuine misses
     * (no match / credit-limited bare match) land here — a transient API/Guzzle
     * error is never recorded, so it stays freely retryable. The bulk sync reads
     * this to keep a few-day cooldown instead of burning a credit every run.
     */
    public static function recordNoDataAttempt(People $people): void
    {
        $people->set(ConfigurationEnum::APOLLO_LAST_ATTEMPT_AT->value, time());
    }

    /**
     * Was this person attempted within the cooldown window? `$cooldownDays <= 0`
     * disables the gate (always retry).
     */
    public static function isWithinNoDataCooldown(People $people, int $cooldownDays): bool
    {
        if ($cooldownDays <= 0) {
            return false;
        }

        $lastAttempt = $people->get(ConfigurationEnum::APOLLO_LAST_ATTEMPT_AT->value);

        return ! empty($lastAttempt) && (int) $lastAttempt > strtotime("-{$cooldownDays} days");
    }

    /**
     * Did Apollo return anything worth persisting? The echoed-back email does not
     * count — only fields Apollo actually discovered (job, history, socials, etc.).
     */
    public static function hasMeaningfulEnrichment(array $peopleData): bool
    {
        return ! empty($peopleData['employment_history'])
            || ! empty($peopleData['organization']['name'] ?? null)
            || ! empty($peopleData['title'])
            || ! empty($peopleData['headline'])
            || ! empty($peopleData['linkedin_url'])
            || ! empty($peopleData['phone_numbers'])
            || (! empty($peopleData['city']) && ! empty($peopleData['state']) && ! empty($peopleData['country']));
    }

    /**
     * Write a raw Apollo `person` payload onto the People record. Public so the
     * write-back can be exercised deterministically without a live Apollo call.
     */
    public function applyEnrichmentData(array $peopleData): void
    {
        // Only let Apollo set an address when the person has none — its address sync
        // is destructive (soft-deletes addresses not in the input), so for someone who
        // already has an address we skip it rather than risk evicting the real one.
        $address = $this->people->address()->count() === 0
            ? $this->buildAddress($peopleData)
            : [];

        // Contacts are merged additively below — never hand them to UpdatePeopleAction,
        // whose syncContactsForUpdate treats the incoming list as authoritative and
        // soft-deletes every existing contact Apollo didn't return.
        $peopleDto = $this->buildPeopleDto($peopleData, $address);

        new UpdatePeopleAction($this->people, $peopleDto)->execute();

        $this->attachContacts($peopleData);

        $currentOrgIds = [];

        if (! empty($peopleData['organization'])) {
            $organization = $this->setOrganization($peopleData['organization']);
            if ($organization !== null) {
                $currentOrgIds[$organization->getId()] = $organization->getId();
            }
        }

        foreach ($this->updateEmploymentHistory($peopleData['employment_history'] ?? []) as $currentOrgId) {
            $currentOrgIds[$currentOrgId] = $currentOrgId;
        }

        $this->syncCurrentOrganizationLinks(array_values($currentOrgIds));

        $this->updateTodayReport(! empty($peopleData['employment_history']));
    }

    /**
     * Make the person's organization links reflect only where they CURRENTLY work.
     *
     * Apollo can report multiple concurrent current roles, so we keep every current
     * employer and hard-remove the stale links (the pivot has no is_deleted column).
     * Only the organizations_peoples relationship is touched — the Organization rows
     * and the full peoples_employment_history stay intact. We never prune when there
     * is no confirmed current employer, so a bare match can't strip every link.
     *
     * @param list<int> $currentOrgIds
     */
    private function syncCurrentOrganizationLinks(array $currentOrgIds): void
    {
        if (empty($currentOrgIds)) {
            return;
        }

        OrganizationPeople::query()
            ->where('peoples_id', $this->people->getId())
            ->whereNotIn('organizations_id', $currentOrgIds)
            ->delete();
    }

    /**
     * Merge Apollo-discovered contacts onto the person without removing anything.
     * Mirrors the idempotent upsert used by the Intras importer: keyed on
     * (person, normalized value, type), so re-runs update weight/opt-out in place
     * and never delete contacts the enrichment simply didn't return.
     */
    private function attachContacts(array $peopleData): void
    {
        foreach ($this->buildContacts($peopleData) as $contact) {
            $value = Contact::normalizeValue($contact['value'], $contact['contacts_types_id']);
            if ($value === '') {
                continue;
            }

            Contact::updateOrCreate(
                [
                    'peoples_id' => $this->people->getId(),
                    'value' => $value,
                    'contacts_types_id' => $contact['contacts_types_id'],
                ],
                [
                    'weight' => $contact['weight'],
                    'is_opt_out' => 0,
                ]
            );
        }
    }

    private function buildPeopleDto(array $peopleData, array $address): PeopleData
    {
        return PeopleData::from([
            'app' => $this->app,
            'branch' => $this->people->company->defaultBranch,
            'user' => $this->people->user,
            // Keep the name we already have — Apollo normalizes/truncates names
            // (e.g. drops compound surnames), so enrichment must not rename people.
            // Only fall back to Apollo when our own value is blank.
            'firstname' => $this->people->firstname ?: ($peopleData['first_name'] ?? ''),
            'middlename' => $this->people->middlename,
            'lastname' => $this->people->lastname ?: ($peopleData['last_name'] ?? ''),
            'contacts' => ContactData::collect([], DataCollection::class),
            'address' => AddressData::collect($address, DataCollection::class),
            'id' => $this->people->getId(),
            'custom_fields' => [
                'headline' => $peopleData['headline'] ?? '',
                'title' => $peopleData['title'] ?? '',
            ],
            'location' => [
                'city' => $address[0]['city'] ?? null,
                'state' => $address[0]['state'] ?? null,
                'country' => $address[0]['countries_id'] ?? null,
            ],
        ]);
    }

    private function updateTodayReport(bool $successExtraction): void
    {
        $company = $this->people->company;
        $todayReport = $this->getTodayReport();
        $today = date('Y-m-d');

        $todayReport[$today] = [
            'total' => $todayReport[$today]['total'] + 1 ?? 1,
            'success' => $successExtraction ? ($todayReport[$today]['success'] + 1 ?? 1) : ($todayReport[$today]['success'] ?? 0),
            'processed' => $todayReport[$today]['processed'] + 1 ?? 1,
            'failed' => ! $successExtraction ? ($todayReport[$today]['failed'] + 1 ?? 1) : ($todayReport[$today]['failed'] ?? 0),
        ];

        $company->set(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value, $todayReport);
        $this->people->set(ConfigurationEnum::APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS->value, time());
    }

    private function setOrganization(array $organizationData): ?OrganizationModel
    {
        if (empty($organizationData['name'])) {
            return null;
        }

        $organization = new CreateOrganizationAction(
            new Organization(
                $this->people->company,
                $this->people->user,
                $this->app,
                $organizationData['name'],
                $organizationData['email'] ?? null,
                $organizationData['sanitized_phone'] ?? null,
                $organizationData['raw_address'] ?? null,
                $organizationData['city'] ?? null
            )
        )->execute();

        OrganizationPeople::addPeopleToOrganization($organization, $this->people);

        $this->people->set('company', $organizationData['name']);

        if (! empty($organizationData['logo_url'])) {
            $organization->set('logo', $organizationData['logo_url']);
        }

        if (! empty($organizationData['linkedin_url'])) {
            $organization->set('linkedin_url', $organizationData['linkedin_url']);
        }

        if (! empty($organizationData['sanitized_phone'])) {
            $organization->set('phone', $organizationData['sanitized_phone']);
        }

        if (! empty($organizationData['short_description'])) {
            $organization->set('short_description', $organizationData['short_description']);
        }

        if (! empty($organizationData['raw_address'])) {
            $organization->address = $organizationData['raw_address'];
            $organization->set('country', $organizationData['country']);
            $organization->save();
        }

        if (! empty($organizationData['keywords'])) {
            $organization->addTags($organizationData['keywords']);
        }

        return $organization;
    }

    /**
     * @return list<int> organization ids the person CURRENTLY works at (status=1),
     *                   so the caller can prune the pivot to just those.
     */
    private function updateEmploymentHistory(array $employmentHistory): array
    {
        $currentOrgIds = [];

        foreach ($employmentHistory as $employment) {
            if (empty($employment['organization_name'])) {
                continue;
            }

            $organization = new CreateOrganizationAction(
                new Organization(
                    $this->people->company,
                    $this->people->user,
                    $this->app,
                    $employment['organization_name'],
                    $employment['raw_address'] ?? null
                )
            )->execute();

            // Link + remember every current employer (even one without a title) so the
            // pivot reflects all concurrent roles, not just Apollo's primary organization.
            if ((int) ($employment['current'] ?? 0) === 1) {
                $this->people->set('company', $employment['organization_name']);
                OrganizationPeople::addPeopleToOrganization($organization, $this->people);
                $currentOrgIds[] = $organization->getId();
            }

            if (empty($employment['title'])) {
                continue;
            }

            PeopleEmploymentHistory::firstOrCreate([
                'status' => (int) $employment['current'],
                'start_date' => $employment['start_date'],
                'end_date' => $employment['end_date'],
                'position' => $employment['title'],
                'apps_id' => $this->app->getId(),
                'peoples_id' => $this->people->id,
                'organizations_id' => $organization->getId(),
            ]);

            $this->assignAudienceSegment($employment['title']);
        }

        return $currentOrgIds;
    }

    private function assignAudienceSegment(string $jobTitle): void
    {
        $segments = $this->app->get(ConfigurationEnum::APOLLO_JOB_SEGMENTS->value);
        if (empty($segments)) {
            return;
        }

        $this->people->addTags($this->getMatchingSegments($segments, $jobTitle));
    }

    private function getMatchingSegments(array $segments, string $jobTitle): array
    {
        $jobTitle = strtolower($jobTitle);
        $tags = [];

        foreach ($segments as $segment => $data) {
            foreach ($data['keywords'] as $keyword) {
                if (Str::contains($jobTitle, strtolower($keyword))) {
                    $tags[strtolower($segment)] = strtolower($segment);
                }
            }
        }

        return $tags;
    }

    private function buildContacts(array $peopleData): array
    {
        $linkedinId = ContactType::getByName('LinkedIn')->getId();
        $contacts = [
            $this->createContact($linkedinId, $peopleData['linkedin_url'] ?? null, 0),
            $this->createContact(ContactTypeEnum::EMAIL->value, $peopleData['email'] ?? null, 1),
        ];

        if (! empty($peopleData['phone_numbers'][0])) {
            $contacts[] = $this->createContact(ContactTypeEnum::PHONE->value, $peopleData['phone_numbers'][0]['sanitized_number'], 2);
        }

        return $this->filterEmptyContacts($contacts);
    }

    private function createContact(int $typeId, ?string $value, int $weight): array
    {
        return [
            'contacts_types_id' => $typeId,
            'value' => $value,
            'weight' => $weight,
        ];
    }

    private function filterEmptyContacts(array $contacts): array
    {
        return array_values(array_filter($contacts, fn ($contact) => ! empty($contact['value'])));
    }

    private function buildAddress(array $peopleData): array
    {
        if (empty($peopleData['country']) || empty($peopleData['state']) || empty($peopleData['city'])) {
            return [];
        }

        try {
            $state = States::getByName($peopleData['state']);
            $country = Countries::getByName($peopleData['country']);

            return [
                $this->createAddress($peopleData, $state, $country),
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    private function createAddress(array $peopleData, ?States $state, Countries $country): array
    {
        return [
            'address' => '',
            'address_2' => '',
            'city' => $peopleData['city'],
            'state' => $state?->code,
            'county' => '',
            'zip' => '',
            'city_id' => null,
            'state_id' => $state?->id,
            'countries_id' => $country->getId(),
            'country' => $country->name,
        ];
    }

    private function getTodayReport(): array
    {
        $report = $this->people->company->get(ConfigurationEnum::APOLLO_COMPANY_REPORTS->value) ?? [];

        if (! isset($report[date('Y-m-d')])) {
            $report[date('Y-m-d')] = ['total' => 0, 'success' => 0, 'processed' => 0, 'failed' => 0];
        }

        return $report;
    }

    private function response(string $status, string $message, array $data = []): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'people_id' => $this->people->id,
            'data' => $data,
        ];
    }
}
