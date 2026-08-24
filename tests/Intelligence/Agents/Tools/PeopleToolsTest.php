<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Services\CsvExportService;
use Kanvas\Guild\Customers\Contracts\PeopleCandidateSourceInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Search\PeopleSqlCandidateSource;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Neuron\Exporters\PeopleMatchRecordExporter;
use Kanvas\Intelligence\Agents\Neuron\Exporters\RecordExporterRegistry;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\ExportRecordsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPeopleBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LinkPersonToOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ManagePersonContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\MergePeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\RevalidatePersonEmailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetPersonCustomFieldsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TagPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdatePersonTool;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\CapturingCsvExportService;
use Tests\TestCase;

final class PeopleToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function test_find_person_by_name_and_email(): void
    {
        $person = $this->makePerson('Findableuniq', 'Contactcc');
        $email = 'findable-' . uniqid() . '@x.test';
        $person->addEmail($email, 0, 0);

        $byName = $this->tool(new FindPersonTool())->__invoke(query: 'Findableuniq');
        $this->assertNotNull(collect($byName['people'])->firstWhere('person_id', (int) $person->getId()));

        $byEmail = $this->tool(new FindPersonTool())->__invoke(query: $email);
        $match = collect($byEmail['people'])->firstWhere('person_id', (int) $person->getId());
        $this->assertNotNull($match);
        $this->assertSame($email, $match['email']);
    }

    public function test_get_person_returns_profile_with_scrubbed_custom_fields(): void
    {
        $person = $this->makePerson('Profileuniq', 'Contactcc');
        $person->addEmail('profile-' . uniqid() . '@x.test', 0, 0);
        $person->addPhone('+18095551234', 1, 0);
        $person->set('department', 'Sales');
        $person->set('apollo_id', 'apl_999');

        $result = $this->tool(new GetPersonTool())->__invoke(person_id: (int) $person->getId());

        $this->assertSame((int) $person->getId(), $result['person_id']);
        $this->assertCount(1, $result['emails']);
        $this->assertCount(1, $result['phones']);
        $this->assertTrue($result['phones'][0]['is_opt_out']);
        $this->assertSame('Sales', $result['custom_fields']['department']);
        $this->assertArrayNotHasKey('apollo_id', $result['custom_fields']);
    }

    public function test_create_person_then_update_and_set_custom_fields(): void
    {
        $created = $this->tool(new CreatePersonTool())->__invoke(
            firstname: 'Createduniq',
            lastname: 'Contactcc',
            email: 'created-' . uniqid() . '@x.test',
            title: 'Analyst',
        );
        $this->assertArrayHasKey('person_id', $created);
        $personId = (int) $created['person_id'];

        $updated = $this->tool(new UpdatePersonTool())->__invoke(person_id: $personId, title: 'Manager', lastname: 'Renamedcc');
        $this->assertSame('Manager', $updated['title']);

        $setFields = $this->tool(new SetPersonCustomFieldsTool())->__invoke(
            person_id: $personId,
            custom_fields: ['seniority' => 'director', 'source' => 'referral'],
        );
        $this->assertSame('director', $setFields['set']['seniority']);

        /** @var People $reloaded */
        $reloaded = People::getByIdFromCompanyApp($personId, $this->currentCompany, $this->currentApp);
        $this->assertSame('director', $reloaded->get('seniority'));
        $this->assertSame('Renamedcc', $reloaded->lastname);
    }

    /**
     * custom_fields is declared as a JSON-object STRING because Gemini rejects an OBJECT
     * schema with no `properties` — so the string form is what the model actually sends.
     */
    public function test_custom_fields_accept_a_json_object_string(): void
    {
        $created = $this->tool(new CreatePersonTool())->__invoke(
            firstname: 'Jsonfielduniq',
            lastname: 'Contactjs',
            email: 'json-' . uniqid() . '@x.test',
            custom_fields: '{"seniority": "manager"}',
        );
        $personId = (int) $created['person_id'];

        $setFields = $this->tool(new SetPersonCustomFieldsTool())->__invoke(
            person_id: $personId,
            custom_fields: '{"source": "referral", "score": 42}',
        );
        $this->assertSame('referral', $setFields['set']['source']);

        /** @var People $reloaded */
        $reloaded = People::getByIdFromCompanyApp($personId, $this->currentCompany, $this->currentApp);
        $this->assertSame('manager', $reloaded->get('seniority'));
        $this->assertSame('referral', $reloaded->get('source'));
        $this->assertSame(42, $reloaded->get('score'));
    }

    public function test_set_person_custom_fields_rejects_unparseable_json(): void
    {
        $person = $this->makePerson('Badjsonuniq', '');

        $result = $this->tool(new SetPersonCustomFieldsTool())->__invoke(
            person_id: (int) $person->getId(),
            custom_fields: 'seniority = director',
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function test_manage_person_contact_adds_and_opts_out(): void
    {
        $person = $this->makePerson('Contactmgruniq', '');

        $result = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            kind: 'email',
            value: 'optout-' . uniqid() . '@x.test',
            is_opt_out: true,
        );

        $this->assertSame('email', $result['contact']['kind']);
        $this->assertTrue($result['contact']['is_opt_out']);
    }

    public function test_tag_person_adds_a_tag(): void
    {
        // Tags live on the `social` connection while People is on `crm`. The add writes the pivot via
        // the crm connection (held under the test transaction), so a same-test remove would deadlock
        // on that lock — add and remove are exercised in separate tests. Persistence is proven outside
        // a transaction; here we assert the tool drives addTags end-to-end and returns its contract.
        $person = $this->makePerson('Taggableuniq', '');

        $added = $this->tool(new TagPersonTool())->__invoke(person_id: (int) $person->getId(), tags: ['vip-' . uniqid()]);

        $this->assertSame((int) $person->getId(), $added['person_id']);
        $this->assertSame('Tags added.', $added['message']);
        $this->assertIsArray($added['tags']);
    }

    public function test_tag_person_remove_is_safe_when_tag_absent(): void
    {
        $person = $this->makePerson('Untaggableuniq', '');

        $removed = $this->tool(new TagPersonTool())->__invoke(
            person_id: (int) $person->getId(),
            tags: ['nonexistent-' . uniqid()],
            remove: true,
        );

        $this->assertSame('Tags removed.', $removed['message']);
    }

    public function test_tag_person_requires_a_tag(): void
    {
        $person = $this->makePerson('Notagsuniq', '');
        $result = $this->tool(new TagPersonTool())->__invoke(person_id: (int) $person->getId(), tags: []);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_link_person_to_organization_writes_pivot_and_employment(): void
    {
        $person = $this->makePerson('Linkableuniq', 'Contactcc');
        $org = $this->seedOrg('LinkTargetUniqOrg');

        $result = $this->tool(new LinkPersonToOrganizationTool())->__invoke(
            person_id: (int) $person->getId(),
            organization_id: (int) $org->getId(),
            title: 'Head of Ops',
        );

        $this->assertSame((int) $org->getId(), $result['organization']['organization_id']);
        $this->assertTrue(
            OrganizationPeople::where('organizations_id', $org->getId())
                ->where('peoples_id', $person->getId())
                ->exists()
        );
        $this->assertSame('Head of Ops', $result['title']);
    }

    public function test_revalidate_person_email_with_no_email_is_a_noop(): void
    {
        $person = $this->makePerson('Noemailuniq', '');

        $result = $this->tool(new RevalidatePersonEmailTool())->__invoke(person_id: (int) $person->getId());

        $this->assertSame((int) $person->getId(), (int) $result['people_id']);
        $this->assertSame([], $result['validated']);
    }

    public function test_merge_people_moves_data_and_soft_deletes_source(): void
    {
        $source = $this->makePerson('Mergesourceuniq', 'Contactcc');
        $target = $this->makePerson('Mergetargetuniq', 'Contactcc');

        $result = $this->tool(new MergePeopleTool())->__invoke(
            source_person_id: (int) $source->getId(),
            target_person_id: (int) $target->getId(),
        );

        $this->assertSame((int) $target->getId(), $result['person_id']);
        $this->assertTrue((bool) $source->refresh()->is_deleted);
    }

    public function test_get_person_unknown_id_returns_error(): void
    {
        $result = $this->tool(new GetPersonTool())->__invoke(person_id: 999999999);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_list_people_filters_by_organization(): void
    {
        $org = $this->seedOrg('ListPeopleUniqOrg');
        $person = $this->makePerson('Listpeopleuniq', 'Contactcc');
        $person->organizations()->attach($org->getId(), ['created_at' => now()]);

        $result = $this->tool(new ListPeopleTool())->__invoke(organization: 'ListPeopleUniqOrg');

        $match = collect($result['people'])->firstWhere('person_id', (int) $person->getId());
        $this->assertNotNull($match);
        $this->assertSame('ListPeopleUniqOrg', $match['organization']);
    }

    public function test_list_people_requires_a_filter(): void
    {
        $result = $this->tool(new ListPeopleTool())->__invoke();
        $this->assertArrayHasKey('error', $result);
    }

    public function test_export_records_people_returns_a_file_reference(): void
    {
        $this->fakeCsvUpload();

        $org = $this->seedOrg('ExportRecordsUniqOrg');
        $person = $this->makePerson('Exportrecuniq', 'Contactcc');
        $person->organizations()->attach($org->getId(), ['created_at' => now()]);
        $person->addEmail('exprec-' . uniqid() . '@x.test', 0, 0);

        $result = $this->tool(new ExportRecordsTool())->__invoke(
            record_type: 'people',
            filters: ['organization' => 'ExportRecordsUniqOrg'],
        );

        $this->assertStringStartsWith('https://fake.test/people', $result['file_url']);
        $this->assertGreaterThanOrEqual(1, $result['row_count']);
    }

    public function test_export_records_organizations_returns_a_file_reference(): void
    {
        $this->fakeCsvUpload();
        $this->seedOrg('ExportOrgUniqCo');

        $result = $this->tool(new ExportRecordsTool())->__invoke(
            record_type: 'organizations',
            filters: ['search' => 'ExportOrgUniqCo'],
        );

        $this->assertStringStartsWith('https://fake.test/organizations', $result['file_url']);
        $this->assertSame(1, $result['row_count']);
    }

    public function test_export_records_people_match_marks_found_and_missing_in_order(): void
    {
        $this->fakeCsvUpload();

        $org = $this->seedOrg('MatchExportUniqOrg');
        $person = $this->makePerson('Jorgelinauniq', 'Duranuniq');
        $person->organizations()->attach($org->getId(), ['created_at' => now()]);
        $email = 'match-' . uniqid() . '@x.test';
        $person->addEmail($email, 0, 0);

        $rows = new PeopleMatchRecordExporter()->rows(
            $this->currentApp,
            $this->currentCompany,
            ['names' => "Nobodyuniq Herenope\nJorgelinauniq Duranuniq"],
        );

        $this->assertCount(2, $rows);

        $this->assertSame('Nobodyuniq Herenope', $rows[0][0]);
        $this->assertSame('No', $rows[0][1]);
        $this->assertSame('', $rows[0][2]);

        $this->assertSame('Jorgelinauniq Duranuniq', $rows[1][0]);
        $this->assertSame('Yes', $rows[1][1]);
        $this->assertSame((int) $person->getId(), $rows[1][2]);
        $this->assertSame($email, $rows[1][3]);
        $this->assertSame('MatchExportUniqOrg', $rows[1][5]);
    }

    public function test_export_records_people_match_returns_a_file_reference(): void
    {
        $this->fakeCsvUpload();
        $this->makePerson('Fileresuniq', 'Matchuniq');

        $result = $this->tool(new ExportRecordsTool())->__invoke(
            record_type: 'people_match',
            filters: ['names' => 'Fileresuniq Matchuniq, Ghostuniq Absentuniq'],
        );

        $this->assertStringStartsWith('https://fake.test/people_match', $result['file_url']);
        $this->assertSame(2, $result['row_count']);
    }

    public function test_export_records_people_match_requires_names(): void
    {
        $result = $this->tool(new ExportRecordsTool())->__invoke(record_type: 'people_match', filters: []);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_export_records_people_match_exports_past_the_term_cap_as_one_file(): void
    {
        $this->fakeCsvUpload();

        $names = implode(',', array_map(fn (int $i): string => 'Personuniq Number' . $i, range(1, 101)));

        $result = $this->tool(new ExportRecordsTool())->__invoke(
            record_type: 'people_match',
            filters: ['names' => $names],
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringStartsWith('https://fake.test/people_match', $result['file_url']);
        $this->assertSame(101, $result['row_count']);
    }

    public function test_export_records_people_match_rejects_more_than_the_export_ceiling(): void
    {
        $names = implode(',', array_map(fn (int $i): string => 'Personuniq Number' . $i, range(1, 1001)));

        $result = $this->tool(new ExportRecordsTool())->__invoke(
            record_type: 'people_match',
            filters: ['names' => $names],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('batches', $result['error']);
    }

    public function test_export_records_people_match_agrees_with_find_people_bulk(): void
    {
        $person = $this->makePerson('Agreesuniq', 'Matcheruniq');
        $names = 'Agreesuniq Matcheruniq, Missinguniq Personuniq';

        $chat = $this->tool(new FindPeopleBulkTool())->__invoke(names: $names);
        $rows = new PeopleMatchRecordExporter()->rows($this->currentApp, $this->currentCompany, ['names' => $names]);

        $this->assertSame(
            array_map(fn (array $result): bool => $result['found'], $chat['results']),
            array_map(fn (array $row): bool => $row[1] === 'Yes', $rows),
        );
        $this->assertSame((int) $person->getId(), $rows[0][2]);
    }

    public function test_people_match_survives_a_common_surname_flooding_the_candidate_set(): void
    {
        // Fillers first so they hold the lower ids: a prefilter that admits every one-token match
        // hands the capped, unordered candidate query nothing but fillers, and the person we asked
        // about comes back "not found" while sitting in the directory.
        for ($i = 0; $i < 10; $i++) {
            $this->makePerson('Filleruniq' . $i, 'Floodsurnameuniq');
        }
        $target = $this->makePerson('Rarefirstuniq', 'Floodsurnameuniq');

        $exporter = new class () extends PeopleMatchRecordExporter {
            protected function candidateSource(Apps $app): PeopleCandidateSourceInterface
            {
                return new class () extends PeopleSqlCandidateSource {
                    protected const int BULK_MAX_CANDIDATE_ROWS = 5;
                };
            }
        };

        $rows = $exporter->rows(
            $this->currentApp,
            $this->currentCompany,
            ['names' => 'Rarefirstuniq Floodsurnameuniq'],
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Yes', $rows[0][1]);
        $this->assertSame((int) $target->getId(), $rows[0][2]);
    }

    public function test_export_records_participants_requires_version(): void
    {
        $result = $this->tool(new ExportRecordsTool())->__invoke(record_type: 'event_participants');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_export_records_rejects_unknown_type(): void
    {
        $result = $this->tool(new ExportRecordsTool())->__invoke(record_type: 'nonexistent_type');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_export_registry_covers_all_domains(): void
    {
        $types = new RecordExporterRegistry()->types();

        $this->assertEqualsCanonicalizing(
            [
                'people',
                'people_match',
                'organizations',
                'event_participants',
                'products',
                'employees',
                'orders',
                'affiliate_commissions',
            ],
            $types,
        );
    }

    public function test_cross_domain_exporters_query_without_error(): void
    {
        // Exercises the inventory/HR/sales queries + eager loads against real relations/columns
        // (data may be empty for the test company; the query shape must resolve).
        $registry = new RecordExporterRegistry();

        foreach (['products', 'employees', 'orders'] as $type) {
            $rows = $registry->for($type)->rows($this->currentApp, $this->currentCompany, []);
            $this->assertIsArray($rows);
        }
    }

    private function fakeCsvUpload(): void
    {
        $this->instance(CsvExportService::class, new CapturingCsvExportService());
    }

    /**
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function tool(object $tool): object
    {
        return $tool->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function makePerson(string $first, string $last): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);
    }

    private function seedOrg(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
