<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Baka\Support\Str;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Actions\ValidatePeopleEmailAction;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum as MailgunConfigurationEnum;
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
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as LedgerEventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Spatie\LaravelData\DataCollection;
use Throwable;

class EnrichPeopleFromApolloAction
{
    /** Set during updateEmploymentHistory when the new current employer didn't exist in the CRM yet. */
    private bool $newEmployerIsNewAccount = false;

    /**
     * Org ids inserted (not found) during this run. Tracked across BOTH creation
     * points — setOrganization runs before updateEmploymentHistory, so the second
     * firstOrCreate of the same org reports wasRecentlyCreated=false; this set is
     * the reliable "was this employer net-new" signal.
     *
     * @var int[]
     */
    private array $orgsCreatedThisRun = [];

    public function __construct(
        protected People $people,
        protected Apps $app
    ) {
    }

    private const array DECISION_MAKER_SENIORITY = [
        'owner',
        'founder',
        'c_suite',
        'partner',
        'vp',
        'head',
        'director',
    ];

    public function execute(): array
    {
        try {
            $peopleData = new ScreeningAction($this->people, $this->app)->execute();
        } catch (GuzzleException $e) {
            return $this->response('failed', $e->getMessage());
        }

        if (empty($peopleData)) {
            self::recordNoDataAttempt($this->people);
            $this->validatePeopleEmails();

            return $this->response('failed', 'No Apollo match found');
        }

        // A free / credit-exhausted Apollo key still returns a bare match (just the
        // name + the email we sent), but no title, LinkedIn, employment history,
        // location or phone. Writing that back gains nothing and — worse — would let
        // the empty payload clobber existing data. Bail out without touching anything.
        if (! $this->hasMeaningfulEnrichment($peopleData)) {
            self::recordNoDataAttempt($this->people);
            $this->validatePeopleEmails();

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

    /** Best-effort, config-gated: a missing Mailgun key or a validation outage never fails the enrichment. */
    private function validatePeopleEmails(): void
    {
        $hasMailgunKey = ! empty($this->app->get(MailgunConfigurationEnum::API_KEY->value));

        if (! $hasMailgunKey) {
            return;
        }

        try {
            new ValidatePeopleEmailAction($this->people, $this->app)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

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
        // Snapshot BEFORE any write below — UpdatePeopleAction overwrites the title and
        // setOrganization() links the new employer, so the diff must read the old state first.
        $this->newEmployerIsNewAccount = false;
        $this->orgsCreatedThisRun = [];
        $previousJob = $this->describeCurrentJob();
        $previousEmployerOrgIds = $this->currentEmployerOrgIds();
        $before = $this->snapshotEnrichmentProfile();

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

        foreach ($this->updateEmploymentHistory($peopleData['employment_history'] ?? [], $previousJob, $previousEmployerOrgIds) as $currentOrgId) {
            $currentOrgIds[$currentOrgId] = $currentOrgId;
        }

        $this->syncCurrentOrganizationLinks(array_values($currentOrgIds));

        $this->updateTodayReport(! empty($peopleData['employment_history']));

        $after = $this->snapshotEnrichmentProfile();

        $diff = $this->buildEnrichmentDiff($before, $after, $peopleData);
        $this->emitEnrichmentEvent($diff, $after['company']);

        if (self::apolloTouchedEmail($diff)) {
            $this->validatePeopleEmails();
        }
    }

    /**
     * We only re-validate when Apollo handed us a fresh address — not on every success.
     *
     * @param array<string, mixed> $diff
     */
    public static function apolloTouchedEmail(array $diff): bool
    {
        if (isset($diff['email_changed'])) {
            return true;
        }

        foreach ($diff['contacts_added'] ?? [] as $signature) {
            $typeId = (int) explode(':', (string) $signature, 2)[0];
            if (Contact::isEmailType($typeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{title: string, headline: string, company: string, seniority: string, email: string, address_count: int, contacts: string[]}
     */
    private function snapshotEnrichmentProfile(): array
    {
        return [
            'title' => (string) ($this->people->get('title') ?? ''),
            'headline' => (string) ($this->people->get('headline') ?? ''),
            'company' => (string) ($this->people->get('company') ?? ''),
            'seniority' => strtolower((string) ($this->people->get('seniority') ?? '')),
            'email' => $this->currentPrimaryEmail(),
            'address_count' => (int) $this->people->address()->count(),
            'contacts' => $this->contactSignatures(),
        ];
    }

    private function currentPrimaryEmail(): string
    {
        $email = $this->people->contacts()
            ->where('contacts_types_id', ContactTypeEnum::EMAIL->value)
            ->value('value');

        return strtolower(trim((string) $email));
    }

    /**
     * @return string[] one "typeId:value" entry per contact, for set-diffing additions
     */
    private function contactSignatures(): array
    {
        $signatures = [];
        foreach ($this->people->contacts()->get() as $contact) {
            $signatures[] = (int) $contact->contacts_types_id . ':' . (string) $contact->value;
        }

        return $signatures;
    }

    /**
     * @param array{title: string, headline: string, company: string, seniority: string, email: string, address_count: int, contacts: string[]} $before
     * @param array{title: string, headline: string, company: string, seniority: string, email: string, address_count: int, contacts: string[]} $after
     * @param array<string, mixed>   $peopleData raw Apollo payload (for the email Apollo returned)
     *
     * @return array<string, mixed> only the fields Apollo actually changed this run
     */
    private function buildEnrichmentDiff(array $before, array $after, array $peopleData): array
    {
        $diff = [];

        // title / current_employer render as "Before → After" rows — only a real transition
        // counts; a first-time fill (empty `from`) would read as a false move.
        if (self::isRealTransition($before['title'], $after['title'])) {
            $diff['title'] = ['from' => $before['title'], 'to' => $after['title']];
        }

        if (self::isRealTransition($before['company'], $after['company'])) {
            $diff['current_employer'] = ['from' => $before['company'], 'to' => $after['company']];
        }

        // A brand-new employer the CRM didn't have. Tracked independently of the move so a
        // first-time employer fill still surfaces as a net-new account without faking a
        // "X → X" current_employer row.
        if ($this->newEmployerIsNewAccount && $after['company'] !== '') {
            $diff['new_account'] = true;
        }

        // Promotion = crossed INTO a decision-maker seniority from a KNOWN lower one. A blank
        // prior seniority means we just learned their level — that's detection, not a promotion.
        if ($before['seniority'] !== '' && $this->isDecisionMaker($after['seniority']) && ! $this->isDecisionMaker($before['seniority'])) {
            $diff['seniority_promoted'] = ['from' => $before['seniority'], 'to' => $after['seniority']];
        }

        // Email change ≠ email add: only when a prior email existed and Apollo returned a different one.
        $apolloEmail = strtolower(trim((string) ($peopleData['email'] ?? '')));
        if ($before['email'] !== '' && $apolloEmail !== '' && $before['email'] !== $apolloEmail) {
            $diff['email_changed'] = ['from' => $before['email'], 'to' => $apolloEmail];
        }

        $addedContacts = array_values(array_diff($after['contacts'], $before['contacts']));
        if ($addedContacts !== []) {
            $diff['contacts_added'] = $addedContacts;
        }

        if ($before['address_count'] === 0 && $after['address_count'] > 0) {
            $diff['location_added'] = true;
        }

        return $diff;
    }

    private function isDecisionMaker(string $seniority): bool
    {
        return $seniority !== '' && in_array($seniority, self::DECISION_MAKER_SENIORITY, true);
    }

    /**
     * A genuine field transition for the change feed: both sides present and different
     * (case-insensitively, so "Leaderville" → "leaderville" is not a fake move). First
     * fills (empty `from`) and no-ops are NOT transitions — surfacing them as
     * "Before → After" is the noise this suppresses. Shared with the backfill and
     * cleanup commands so all three agree on what counts as a real change.
     */
    public static function isRealTransition(?string $from, ?string $to): bool
    {
        $from = trim((string) $from);
        $to = trim((string) $to);

        return $from !== '' && $to !== '' && strcasecmp($from, $to) !== 0;
    }

    private function trackOrgIfCreated(OrganizationModel $organization): void
    {
        if ($organization->wasRecentlyCreated) {
            $this->orgsCreatedThisRun[] = (int) $organization->getId();
        }
    }

    /**
     * Best-effort: a ledger outage must never fail an enrichment that already succeeded.
     *
     * @param array<string, mixed> $diff
     */
    private function emitEnrichmentEvent(array $diff, string $currentCompany): void
    {
        // `contacts_added` stays in the in-memory diff (apolloTouchedEmail reads it to decide
        // email re-validation) but is NOT a "Before → After" change — keep it out of the
        // persisted event so it never renders as a row in the change feed.
        $changes = $diff;
        unset($changes['contacts_added']);

        try {
            new AppendEventAction(
                new LedgerEventData(
                    app: $this->app,
                    company: $this->people->company,
                    sourceDomain: 'Guild',
                    eventType: 'people.enriched',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: People::class,
                    sourceEntityId: (int) $this->people->getId(),
                    actorType: 'System',
                    payload: [
                        'source' => 'apollo',
                        // Current employer at enrichment time — lets the cleanup report group
                        // leads by company entirely within the ledger (no People→Org join).
                        'company' => $currentCompany,
                        'changed_fields' => array_keys($changes),
                        'changes' => $changes,
                    ],
                    occurredAt: Carbon::now(),
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
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
                // Keep the existing seniority when Apollo doesn't return one — never clobber.
                'seniority' => $peopleData['seniority'] ?? ($this->people->get('seniority') ?? ''),
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

        $this->trackOrgIfCreated($organization);

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
     * @param array{company: string, title: string} $previousJob            the job on file before this run
     * @param int[]                                  $previousEmployerOrgIds employer org ids linked before this run
     *
     * @return list<int> organization ids the person CURRENTLY works at (status=1),
     *                   so the caller can prune the pivot to just those.
     */
    private function updateEmploymentHistory(
        array $employmentHistory,
        array $previousJob,
        array $previousEmployerOrgIds
    ): array {
        $currentOrgIds = [];
        $orgIdByName = [];

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

            $this->trackOrgIfCreated($organization);
            $orgIdByName[mb_strtolower(trim((string) $employment['organization_name']))] = (int) $organization->getId();

            // Link every current employer (even one without a title) so the pivot reflects
            // all concurrent roles, not just Apollo's primary organization.
            if ((int) ($employment['current'] ?? 0) === 1) {
                OrganizationPeople::addPeopleToOrganization($organization, $this->people);
                $currentOrgIds[] = (int) $organization->getId();
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

        $this->applyCurrentEmployer($employmentHistory, $previousJob, $previousEmployerOrgIds, $orgIdByName);

        return $currentOrgIds;
    }

    /**
     * Anchor the person's current employer to their genuine most-recent ONGOING role, then
     * decide whether that's a real job change. Apollo returns the full history — including
     * roles ended decades ago — and its loop order / top-level `organization` can put a
     * PAST employer in the company field. Picking "current=1 + no end_date + latest start"
     * stops a job left in (say) 2001 from being written as the current employer or surfaced
     * as a false "Antes → Después" move.
     *
     * @param array<int, array<string, mixed>>       $employmentHistory
     * @param array{company: string, title: string}  $previousJob
     * @param int[]                                   $previousEmployerOrgIds
     * @param array<string, int>                      $orgIdByName            lower(name) => org id, from this run
     */
    private function applyCurrentEmployer(
        array $employmentHistory,
        array $previousJob,
        array $previousEmployerOrgIds,
        array $orgIdByName
    ): void {
        $current = $this->resolveCurrentEmployer($employmentHistory);
        if ($current === null) {
            return;
        }

        // Authoritative for the company field — overrides whatever setOrganization() wrote
        // from Apollo's (possibly past) primary org.
        $this->people->set('company', $current['company']);

        $orgId = $orgIdByName[mb_strtolower($current['company'])] ?? null;

        // A real move only when the current employer actually changed AND it's an org we
        // didn't already have on file. Re-confirming the same employer, or Apollo merely
        // backfilling old roles, is not a job change.
        $changedEmployer = strcasecmp($current['company'], $previousJob['company']) !== 0;
        $isNewEmployer = $orgId !== null && ! in_array($orgId, $previousEmployerOrgIds, true);

        if ($changedEmployer && $isNewEmployer) {
            $this->newEmployerIsNewAccount = in_array($orgId, $this->orgsCreatedThisRun, true);
            $this->recordJobChange($previousJob, ['company' => $current['company'], 'title' => $current['title']]);
        }
    }

    /**
     * The person's genuine current employer from Apollo's history: the role they are STILL
     * in (current=1, no end_date), most recent by start_date. Returns null when Apollo
     * reports no ongoing role, leaving the company field as setOrganization() left it.
     *
     * @param array<int, array<string, mixed>> $employmentHistory
     *
     * @return array{company: string, title: string, start_date: string}|null
     */
    private function resolveCurrentEmployer(array $employmentHistory): ?array
    {
        $best = null;

        foreach ($employmentHistory as $employment) {
            if ((int) ($employment['current'] ?? 0) !== 1) {
                continue;
            }

            // An end_date means the role is over — Apollo sometimes leaves a long-past job
            // flagged current=1, so the date is the real signal that it's still ongoing.
            if (! empty($employment['end_date'])) {
                continue;
            }

            $company = trim((string) ($employment['organization_name'] ?? ''));
            if ($company === '') {
                continue;
            }

            $start = (string) ($employment['start_date'] ?? '');

            // ISO Y-m-d sorts chronologically as a string; the latest start wins.
            if ($best === null || strcmp($start, $best['start_date']) > 0) {
                $best = [
                    'company' => $company,
                    'title' => (string) ($employment['title'] ?? ''),
                    'start_date' => $start,
                ];
            }
        }

        return $best;
    }

    /**
     * The job we currently have on file, read before this run overwrites it.
     *
     * @return array{company: string, title: string}
     */
    private function describeCurrentJob(): array
    {
        return [
            'company' => (string) ($this->people->get('company') ?? ''),
            'title' => (string) ($this->people->get('title') ?? ''),
        ];
    }

    /**
     * Organization ids the person is currently linked to (their current employers
     * before this run) — the pivot is kept pruned to current roles elsewhere.
     *
     * @return int[]
     */
    private function currentEmployerOrgIds(): array
    {
        return OrganizationPeople::query()
            ->where('peoples_id', $this->people->getId())
            ->pluck('organizations_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array{company: string, title: string} $previousJob
     * @param array{company: string, title: string} $newJob
     */
    private function recordJobChange(array $previousJob, array $newJob): void
    {
        $this->people->set(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value, time());
        $this->people->set(ConfigurationEnum::APOLLO_LAST_JOB_CHANGE->value, [
            'changed_at' => date('c'),
            'from_company' => $previousJob['company'],
            'from_title' => $previousJob['title'],
            'to_company' => $newJob['company'],
            'to_title' => $newJob['title'],
        ]);
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
