<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Contracts\Enums\ThirdPartyPeopleIdFieldEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\FindPeopleDuplicatesService;
use Tests\TestCase;

class FindPeopleDuplicatesServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_finds_exact_name_duplicates_case_insensitive(): void
    {
        $lastname = 'Puello' . uniqid();
        $a = $this->seedPeople('Arfenis', $lastname);
        $b = $this->seedPeople('arfenis', strtolower($lastname));
        $this->seedPeople('Unrelated', 'Person' . uniqid());

        $groups = new FindPeopleDuplicatesService()->generate($this->kanvasApp, $this->company);

        $group = $this->findGroupContaining($groups, (int) $a->id);
        $this->assertNotNull($group);
        $this->assertSame('exact_name', $group->reason);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $group->member_ids);
        $this->assertSame((int) $a->id, $group->canonical_id);
    }

    public function test_finds_lastname_only_duplicates_between_two_records_missing_firstname(): void
    {
        $lastname = 'Pina' . uniqid();
        $a = $this->seedPeople('', $lastname);
        $b = $this->seedPeople('', $lastname);

        $groups = new FindPeopleDuplicatesService()->generate($this->kanvasApp, $this->company);

        $group = $this->findGroupContaining($groups, (int) $a->id);
        $this->assertNotNull($group);
        $this->assertSame('lastname_match', $group->reason);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $group->member_ids);
    }

    public function test_finds_firstname_only_duplicates_between_two_records_missing_lastname(): void
    {
        $firstname = 'Andres' . uniqid();
        $a = $this->seedPeople($firstname, '');
        $b = $this->seedPeople($firstname, '');

        $groups = new FindPeopleDuplicatesService()->generate($this->kanvasApp, $this->company);

        $group = $this->findGroupContaining($groups, (int) $a->id);
        $this->assertNotNull($group);
        $this->assertSame('firstname_match', $group->reason);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $group->member_ids);
    }

    public function test_shared_lastname_alone_does_not_flag_two_different_people(): void
    {
        $lastname = 'Abreu' . uniqid();
        $alan = $this->seedPeople('Alan', $lastname);
        $pedro = $this->seedPeople('Pedro', $lastname);

        $groups = new FindPeopleDuplicatesService()->generate($this->kanvasApp, $this->company);

        $this->assertNull($this->findGroupContaining($groups, (int) $alan->id));
        $this->assertNull($this->findGroupContaining($groups, (int) $pedro->id));

        $this->assertSame([], new FindPeopleDuplicatesService()->checkRecord($pedro->fresh()));
    }

    public function test_lastname_only_record_does_not_cross_match_against_many_full_name_records(): void
    {
        $lastname = 'Pina' . uniqid();
        foreach (['Andres', 'Pedro', 'Alan', 'Maria', 'Jose'] as $firstname) {
            $this->seedPeople($firstname, $lastname);
        }
        $lonePartial = $this->seedPeople('', $lastname);

        $this->assertSame([], new FindPeopleDuplicatesService()->checkRecord($lonePartial->fresh()));

        $otherPartial = $this->seedPeople('', $lastname);

        $groups = new FindPeopleDuplicatesService()->checkRecord($otherPartial->fresh());
        $group = $this->findGroupContaining($groups, (int) $otherPartial->id);
        $this->assertNotNull($group);
        $this->assertEqualsCanonicalizing([(int) $lonePartial->id, (int) $otherPartial->id], $group->member_ids);
    }

    public function test_finds_email_duplicates_case_insensitive(): void
    {
        $email = 'shared-' . uniqid() . '@test.com';
        $a = $this->seedPeople('Vendor', 'One' . uniqid(), email: strtoupper($email));
        $b = $this->seedPeople('Vendor', 'Two' . uniqid(), email: $email);

        $groups = new FindPeopleDuplicatesService()->generate($this->kanvasApp, $this->company);

        $group = $this->findGroupContaining($groups, (int) $a->id);
        $this->assertNotNull($group);
        $this->assertSame('email_match', $group->reason);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $group->member_ids);
    }

    public function test_finds_external_id_conflict_and_it_wins_over_exact_name(): void
    {
        $lastname = 'Puello' . uniqid();
        $a = $this->seedPeople(
            'Arfenis',
            $lastname,
            externalIdField: ThirdPartyPeopleIdFieldEnum::SALESFORCE_CONTACT_ID,
            externalIdValue: '003xx' . uniqid(),
        );
        $b = $this->seedPeople(
            'Arfenis',
            $lastname,
            externalIdField: ThirdPartyPeopleIdFieldEnum::SALESFORCE_CONTACT_ID,
            externalIdValue: '003xx' . uniqid(),
        );

        $groups = new FindPeopleDuplicatesService()->generate($this->kanvasApp, $this->company);

        $matches = array_values(array_filter(
            $groups,
            fn ($g) => $g->member_ids === [(int) $a->id, (int) $b->id],
        ));

        $this->assertCount(1, $matches, 'Same member set shouldn\'t appear under both dimensions.');
        $this->assertSame('external_id_conflict', $matches[0]->reason, 'external_id_conflict must win over exact_name.');
    }

    public function test_check_record_finds_only_matches_for_that_person(): void
    {
        $lastname = 'Puello' . uniqid();
        $a = $this->seedPeople('Arfenis', $lastname);
        $b = $this->seedPeople('arfenis', strtolower($lastname));
        $unrelated = $this->seedPeople('Unrelated', 'Person' . uniqid());

        $groupsForA = new FindPeopleDuplicatesService()->checkRecord($a->fresh());
        $group = $this->findGroupContaining($groupsForA, (int) $a->id);
        $this->assertNotNull($group);
        $this->assertSame('exact_name', $group->reason);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $group->member_ids);

        $groupsForUnrelated = new FindPeopleDuplicatesService()->checkRecord($unrelated->fresh());
        $this->assertSame([], $groupsForUnrelated);
    }

    public function test_check_record_finds_lastname_match_between_two_records_missing_firstname(): void
    {
        $lastname = 'Pina' . uniqid();
        $a = $this->seedPeople('', $lastname);
        $b = $this->seedPeople('', $lastname);

        $groups = new FindPeopleDuplicatesService()->checkRecord($b->fresh());

        $group = $this->findGroupContaining($groups, (int) $b->id);
        $this->assertNotNull($group);
        $this->assertSame('lastname_match', $group->reason);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], $group->member_ids);
    }

    public function test_singletons_are_not_returned(): void
    {
        $person = $this->seedPeople('Lonely', 'Person' . uniqid(), email: 'lonely-' . uniqid() . '@test.com');

        $groups = new FindPeopleDuplicatesService()->generate($this->kanvasApp, $this->company);

        $this->assertNull($this->findGroupContaining($groups, (int) $person->id));
    }

    private function seedPeople(
        string $firstname,
        string $lastname,
        ?string $email = null,
        ?ThirdPartyPeopleIdFieldEnum $externalIdField = null,
        ?string $externalIdValue = null,
    ): People {
        $people = People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'name' => $firstname . ' ' . $lastname,
        ]);

        if ($email !== null) {
            $people->contacts()->create([
                'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                'value' => $email,
                'weight' => 0,
            ]);
        }

        if ($externalIdField !== null) {
            $people->setCustomFields([$externalIdField->fieldName() => $externalIdValue]);
            $people->saveCustomFields();
        }

        return $people;
    }

    /**
     * @param  list<\Kanvas\Guild\Customers\DataTransferObject\PeopleDuplicateGroup>  $groups
     */
    private function findGroupContaining(array $groups, int $memberId)
    {
        foreach ($groups as $group) {
            if (in_array($memberId, $group->member_ids, true)) {
                return $group;
            }
        }

        return null;
    }
}
