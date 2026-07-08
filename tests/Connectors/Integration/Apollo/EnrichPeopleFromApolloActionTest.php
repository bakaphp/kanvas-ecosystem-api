<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Apollo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

/**
 * Exercises the Apollo write-back deterministically: feed a canned `person`
 * payload through applyEnrichmentData() (no live Apollo call) and assert it
 * lands on the People record — current + past roles, contacts, job custom fields.
 */
final class EnrichPeopleFromApolloActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    public function test_applies_apollo_payload_to_people_record(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        // A contact Apollo will NOT return — must survive the enrichment (additive merge).
        $preExistingEmail = 'existing-' . uniqid() . '@sigma.test';
        $people->addEmail($preExistingEmail, 0, 0);

        $originalFirstname = $people->firstname;
        $originalLastname = $people->lastname;

        $suffix = uniqid();
        $payload = [
            'first_name' => 'Maria',
            'last_name' => 'Perez',
            'headline' => 'CEO at Intras',
            'title' => 'Chief Executive Officer',
            'email' => "maria+{$suffix}@intras.test",
            'linkedin_url' => "https://linkedin.com/in/maria-{$suffix}",
            'phone_numbers' => [['sanitized_number' => '+18095550100']],
            'organization' => ['name' => "Intras Current {$suffix}"],
            'employment_history' => [
                [
                    'organization_name' => "Intras Current {$suffix}",
                    'raw_address' => null,
                    'title' => 'CEO',
                    'current' => 1,
                    'start_date' => '2020-01-01',
                    'end_date' => null,
                ],
                [
                    'organization_name' => "Old Corp {$suffix}",
                    'raw_address' => null,
                    'title' => 'Analyst',
                    'current' => 0,
                    'start_date' => '2010-01-01',
                    'end_date' => '2019-12-31',
                ],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $people->refresh();
        $this->assertSame($originalFirstname, $people->firstname, 'Enrichment must not rename the person.');
        $this->assertSame($originalLastname, $people->lastname, 'Apollo last_name (often truncated) must not overwrite ours.');

        $this->assertSame('Chief Executive Officer', $people->get('title'), 'Current job title is stored as a custom field.');
        $this->assertSame('CEO at Intras', $people->get('headline'));

        $linkedinTypeId = ContactType::getByName('LinkedIn')->getId();
        $contacts = $people->contacts()->get();
        $this->assertTrue(
            $contacts->contains(fn ($c) => (int) $c->contacts_types_id === ContactTypeEnum::EMAIL->value && $c->value === "maria+{$suffix}@intras.test"),
            'Apollo email is attached as an EMAIL contact.',
        );
        $this->assertTrue(
            $contacts->contains(fn ($c) => (int) $c->contacts_types_id === $linkedinTypeId && $c->value === "https://linkedin.com/in/maria-{$suffix}"),
            'Apollo LinkedIn URL is attached as a LinkedIn contact.',
        );

        $history = PeopleEmploymentHistory::where('peoples_id', $people->getId())->get();
        $this->assertCount(2, $history, 'Current and past roles are both recorded.');

        $current = $history->firstWhere('status', 1);
        $this->assertNotNull($current);
        $this->assertSame('CEO', $current->position);

        $past = $history->firstWhere('status', 0);
        $this->assertNotNull($past);
        $this->assertSame('Analyst', $past->position);
        $this->assertSame('2019-12-31', (string) $past->end_date);

        // The pre-existing contact Apollo never returned is still there, not soft-deleted.
        $this->assertTrue(
            $people->contacts()->where('value', $preExistingEmail)->exists(),
            'Enrichment must not delete contacts Apollo did not return.',
        );
    }

    public function test_org_pivot_is_pruned_to_current_employer_only(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        // A stale org link the person already had (e.g. from a prior import) — should be
        // removed from the pivot once we learn their real current employer.
        $staleOrg = $this->seedOrganization('Stale Org ' . uniqid());
        OrganizationPeople::addPeopleToOrganization($staleOrg, $people);

        $suffix = uniqid();
        $payload = [
            'organization' => ['name' => "Current Co {$suffix}"],
            'employment_history' => [
                ['organization_name' => "Current Co {$suffix}", 'title' => 'CEO', 'current' => 1, 'start_date' => '2022-01-01', 'end_date' => null],
                ['organization_name' => "Side Gig {$suffix}", 'title' => 'Advisor', 'current' => 1, 'start_date' => '2023-01-01', 'end_date' => null],
                ['organization_name' => "Old Corp {$suffix}", 'title' => 'Analyst', 'current' => 0, 'start_date' => '2010-01-01', 'end_date' => '2019-12-31'],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $linkedOrgNames = OrganizationPeople::where('peoples_id', $people->getId())
            ->get()
            ->map(fn ($pivot) => $pivot->organization->name)
            ->all();

        // Both concurrent current roles stay linked; the stale link is gone.
        $this->assertContains("Current Co {$suffix}", $linkedOrgNames);
        $this->assertContains("Side Gig {$suffix}", $linkedOrgNames);
        $this->assertNotContains($staleOrg->name, $linkedOrgNames, 'Stale org link must be pruned from the pivot.');

        // The past employer is NOT a pivot link, but its history + org record survive.
        $this->assertNotContains("Old Corp {$suffix}", $linkedOrgNames, 'Past (non-current) employer is not a current-org link.');
        $this->assertTrue(
            Organization::where('name', "Old Corp {$suffix}")->where('companies_id', $company->getId())->exists(),
            'Past employer Organization record is preserved (only the pivot relationship is touched).',
        );
        $this->assertSame(
            3,
            PeopleEmploymentHistory::where('peoples_id', $people->getId())->count(),
            'All employment history rows are kept.',
        );
    }

    public function test_does_not_persist_or_clobber_when_apollo_returns_a_bare_match(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $existingEmail = 'keep-' . uniqid() . '@sigma.test';
        $people->addEmail($existingEmail, 0, 0);

        $action = new EnrichPeopleFromApolloAction($people, $app);

        // A bare match: name + the echoed email only — no enrichment fields. This is
        // exactly what a free / credit-limited key returns.
        $bare = ['first_name' => 'Maria', 'last_name' => 'Lopez', 'email' => $existingEmail];
        $rich = ['employment_history' => [['organization_name' => 'X', 'title' => 'CEO', 'current' => 1, 'start_date' => '2020-01-01', 'end_date' => null]]];

        $this->assertFalse($action->hasMeaningfulEnrichment($bare), 'Echoed-back email alone is not enrichment.');
        $this->assertTrue($action->hasMeaningfulEnrichment($rich), 'Employment history counts as enrichment.');

        // Guard also implies: if we ever reach the write path with a bare payload, the
        // additive merge still leaves the existing contact intact.
        $action->applyEnrichmentData($bare);

        $this->assertTrue(
            $people->contacts()->where('value', $existingEmail)->exists(),
            'A bare payload must never delete existing contacts.',
        );
    }

    public function test_emits_enrichment_ledger_event_with_what_changed(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        // Prior job on file — so the enrichment records a real Antes → Después, not a first fill.
        $people->set('title', 'Marketing Analyst');
        $people->set('company', 'Old Co ' . uniqid());

        $suffix = uniqid();
        $payload = [
            'title' => 'Chief Marketing Officer',
            'headline' => "CMO at Acme {$suffix}",
            'email' => "audit+{$suffix}@acme.test",
            'organization' => ['name' => "Acme {$suffix}"],
            'employment_history' => [
                ['organization_name' => "Acme {$suffix}", 'title' => 'CMO', 'current' => 1, 'start_date' => '2024-01-01', 'end_date' => null],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $event = Event::where('source_entity_type', People::class)
            ->where('source_entity_id', $people->getId())
            ->where('event_type', 'people.enriched')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'A people.enriched ledger event is appended.');

        $payloadOut = (array) $event->payload;
        $this->assertSame('apollo', $payloadOut['source'], 'The event records HOW the change happened.');

        $changedFields = $payloadOut['changed_fields'] ?? [];
        $this->assertContains('title', $changedFields);
        $this->assertContains('current_employer', $changedFields);
        // contacts_added + headline are not change-feed rows — never persisted on the event.
        $this->assertNotContains('contacts_added', $changedFields);
        $this->assertNotContains('headline', $changedFields);

        // change_count is materialized so the feed can filter/paginate on a real column.
        $this->assertSame(count($changedFields), (int) $event->change_count);
        $this->assertGreaterThan(0, (int) $event->change_count);

        // material_change_count = only the real before/after rows (excludes flags like new_account).
        $this->assertSame(Event::countMaterialChanges($payloadOut['changes']), (int) $event->material_change_count);
        $this->assertGreaterThan(0, (int) $event->material_change_count);
        $this->assertLessThanOrEqual((int) $event->change_count, (int) $event->material_change_count);

        $this->assertSame('Marketing Analyst', $payloadOut['changes']['title']['from'], 'The real prior title is captured as Antes.');
        $this->assertSame('Chief Marketing Officer', $payloadOut['changes']['title']['to']);
        $this->assertSame("Acme {$suffix}", $payloadOut['changes']['current_employer']['to']);
        $this->assertNotSame('', $payloadOut['changes']['current_employer']['from'], 'A real move always has a non-empty Antes.');
    }

    public function test_first_fill_is_not_recorded_as_a_change(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        // Brand-new person: no prior title or employer on file.
        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $suffix = uniqid();
        $payload = [
            'title' => 'Supervisora de Operaciones',
            'organization' => ['name' => "Aleli {$suffix}"],
            'employment_history' => [
                ['organization_name' => "Aleli {$suffix}", 'title' => 'Supervisora de Operaciones', 'current' => 1, 'start_date' => '2024-01-01', 'end_date' => null],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $event = Event::where('source_entity_type', People::class)
            ->where('source_entity_id', $people->getId())
            ->where('event_type', 'people.enriched')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $payloadOut = (array) $event->payload;
        $changedFields = $payloadOut['changed_fields'] ?? [];

        // A first-time fill is NOT a "X → X" / empty-from move.
        $this->assertNotContains('title', $changedFields, 'First-time title fill is not a change.');
        $this->assertNotContains('current_employer', $changedFields, 'First-time employer fill is not a change.');

        // ...but a genuinely-new employer still surfaces as a net-new account.
        $this->assertContains('new_account', $changedFields, 'A brand-new employer is still flagged as a new account.');
    }

    public function test_first_fill_seniority_is_not_a_promotion(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        // Brand-new person: no prior seniority on file.
        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $suffix = uniqid();
        $payload = [
            'seniority' => 'director',
            'organization' => ['name' => "Co {$suffix}"],
            'employment_history' => [
                ['organization_name' => "Co {$suffix}", 'title' => 'Director', 'current' => 1, 'start_date' => '2024-01-01', 'end_date' => null],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $event = Event::where('source_entity_type', People::class)
            ->where('source_entity_id', $people->getId())
            ->where('event_type', 'people.enriched')
            ->orderByDesc('id')
            ->first();

        $changed = (array) ($event->payload['changed_fields'] ?? []);
        $this->assertNotContains(
            'seniority_promoted',
            $changed,
            'Learning a seniority for the first time is detection, not a promotion.',
        );
    }

    public function test_emits_seniority_promotion_new_account_and_email_change(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        // Prior state: non-decision-maker seniority, an existing email, an existing employer.
        $people->set('seniority', 'senior');
        $oldEmail = 'old-' . uniqid() . '@intras.test';
        $people->addEmail($oldEmail, 0, 0);
        $oldOrg = $this->seedOrganization('Prev Co ' . uniqid());
        OrganizationPeople::addPeopleToOrganization($oldOrg, $people);
        $people->set('company', $oldOrg->name);

        $suffix = uniqid();
        $newEmail = "new+{$suffix}@bpd.test";
        $payload = [
            'seniority' => 'director',                 // promotion to decision-maker
            'email' => $newEmail,                      // replaced email
            'organization' => ['name' => "New Co {$suffix}"],
            'employment_history' => [
                ['organization_name' => "New Co {$suffix}", 'title' => 'Director', 'current' => 1, 'start_date' => '2024-01-01', 'end_date' => null],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $event = Event::where('source_entity_type', People::class)
            ->where('source_entity_id', $people->getId())
            ->where('event_type', 'people.enriched')
            ->orderByDesc('id')
            ->first();

        $payloadOut = (array) $event->payload;
        $changed = $payloadOut['changed_fields'] ?? [];

        $this->assertContains('seniority_promoted', $changed);
        $this->assertContains('new_account', $changed);
        $this->assertContains('email_changed', $changed);
        $this->assertContains('current_employer', $changed);

        $this->assertSame('director', $payloadOut['changes']['seniority_promoted']['to']);
        $this->assertTrue($payloadOut['changes']['new_account']);
        $this->assertSame($newEmail, $payloadOut['changes']['email_changed']['to']);
        $this->assertSame("New Co {$suffix}", $payloadOut['company'], 'Current employer is stamped on the event for per-company grouping.');
    }

    public function test_records_job_change_when_current_employer_changes(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $suffix = uniqid();
        $oldOrg = $this->seedOrganization("Old Co {$suffix}");
        OrganizationPeople::addPeopleToOrganization($oldOrg, $people);
        $people->set('company', $oldOrg->name);
        $people->set('title', 'Junior Dev');

        $payload = [
            'organization' => ['name' => "New Co {$suffix}"],
            'employment_history' => [
                ['organization_name' => "New Co {$suffix}", 'title' => 'Senior Dev', 'current' => 1, 'start_date' => '2024-01-01', 'end_date' => null],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $this->assertNotEmpty(
            $people->get(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value),
            'A new current employer stamps the job-changed marker.',
        );

        $change = $people->get(ConfigurationEnum::APOLLO_LAST_JOB_CHANGE->value);
        $this->assertSame($oldOrg->name, $change['from_company']);
        $this->assertSame('Junior Dev', $change['from_title']);
        $this->assertSame("New Co {$suffix}", $change['to_company']);
        $this->assertSame('Senior Dev', $change['to_title']);
    }

    public function test_does_not_record_job_change_when_employer_is_unchanged(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $suffix = uniqid();
        $sameOrg = $this->seedOrganization("Same Co {$suffix}");
        OrganizationPeople::addPeopleToOrganization($sameOrg, $people);
        $people->set('company', $sameOrg->name);

        // Apollo re-confirms the same current employer (possibly a fresher title).
        $payload = [
            'organization' => ['name' => "Same Co {$suffix}"],
            'employment_history' => [
                ['organization_name' => "Same Co {$suffix}", 'title' => 'Manager', 'current' => 1, 'start_date' => '2024-01-01', 'end_date' => null],
            ],
        ];

        new EnrichPeopleFromApolloAction($people, $app)->applyEnrichmentData($payload);

        $this->assertEmpty(
            $people->get(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value),
            'Re-confirming the same employer must not be reported as a job change.',
        );
    }

    public function test_no_data_attempt_marks_and_gates_retries_within_cooldown(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        $this->assertFalse(
            EnrichPeopleFromApolloAction::isWithinNoDataCooldown($people, 3),
            'A person never attempted is not in cooldown.',
        );

        EnrichPeopleFromApolloAction::recordNoDataAttempt($people);

        $this->assertTrue(
            EnrichPeopleFromApolloAction::isWithinNoDataCooldown($people, 3),
            'A just-recorded no-data miss is within a 3-day cooldown.',
        );
        $this->assertFalse(
            EnrichPeopleFromApolloAction::isWithinNoDataCooldown($people, 0),
            'A cooldown of 0 days disables the gate — always retry.',
        );

        // An attempt older than the window must fall through and be retried.
        $people->set(ConfigurationEnum::APOLLO_LAST_ATTEMPT_AT->value, strtotime('-10 days'));
        $this->assertFalse(
            EnrichPeopleFromApolloAction::isWithinNoDataCooldown($people, 3),
            'A 10-day-old miss is past a 3-day cooldown and may be retried.',
        );
    }

    public function test_past_employer_is_never_recorded_as_the_current_move(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $suffix = uniqid();

        $person = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->create();

        // She already works at Alpha (ongoing) — seed it as her current employer on file.
        $alpha = $this->seedOrganization("Alpha {$suffix}");
        OrganizationPeople::addPeopleToOrganization($alpha, $person);
        $person->set('company', "Alpha {$suffix}");

        // Apollo runs perfectly and returns her full history: Alpha is ongoing (no end_date),
        // Baninter ended in 2001. Apollo's stale top-level primary org points at Baninter.
        $payload = [
            'organization' => ['name' => "Baninter {$suffix}"],
            'employment_history' => [
                ['organization_name' => "Baninter {$suffix}", 'title' => 'Oficial de Credito', 'current' => 0, 'start_date' => '1999-04-01', 'end_date' => '2001-08-01'],
                ['organization_name' => "Alpha {$suffix}", 'title' => 'Gerente', 'current' => 1, 'start_date' => '2017-11-26', 'end_date' => null],
            ],
        ];

        new EnrichPeopleFromApolloAction($person, $app)->applyEnrichmentData($payload);

        // The genuine current (ongoing, most-recent) employer wins the company field — never the past Baninter.
        $this->assertSame("Alpha {$suffix}", $person->get('company'));

        $event = Event::where('source_entity_type', People::class)
            ->where('source_entity_id', $person->getId())
            ->where('event_type', 'people.enriched')
            ->orderByDesc('id')
            ->first();

        $changes = (array) ($event->payload['changes'] ?? []);

        // No false "move to Baninter" — she never left Alpha, so there is no current_employer change.
        $this->assertArrayNotHasKey('current_employer', $changes, 'A past employer is not recorded as a current move.');
        $this->assertEmpty(
            $person->get(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value),
            'No job-change marker is stamped when the current employer did not change.',
        );
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => static::$cachedUser->getCurrentCompany()->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    public function test_apollo_touched_email_only_triggers_on_a_changed_or_added_email(): void
    {
        $changed = ['email_changed' => ['from' => 'a@x.do', 'to' => 'b@y.do']];
        $this->assertTrue(EnrichPeopleFromApolloAction::apolloTouchedEmail($changed));

        $addedEmail = ['contacts_added' => [ContactTypeEnum::EMAIL->value . ':new@y.do']];
        $this->assertTrue(EnrichPeopleFromApolloAction::apolloTouchedEmail($addedEmail));

        $addedPrimaryEmail = ['contacts_added' => [ContactTypeEnum::PRIMARY_EMAIL->value . ':primary@y.do']];
        $this->assertTrue(EnrichPeopleFromApolloAction::apolloTouchedEmail($addedPrimaryEmail));

        // A non-email contact (e.g. phone) added does not trigger validation.
        $addedPhone = ['contacts_added' => [ContactTypeEnum::PHONE->value . ':+18095550100']];
        $this->assertFalse(EnrichPeopleFromApolloAction::apolloTouchedEmail($addedPhone));

        // A title-only refresh does not trigger validation.
        $titleOnly = ['title' => ['from' => 'Dev', 'to' => 'Lead']];
        $this->assertFalse(EnrichPeopleFromApolloAction::apolloTouchedEmail($titleOnly));

        $this->assertFalse(EnrichPeopleFromApolloAction::apolloTouchedEmail([]));
    }
}
