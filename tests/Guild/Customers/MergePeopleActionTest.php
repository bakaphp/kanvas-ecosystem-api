<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Actions\MergePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Factories\LeadFactory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\NervousSystem\Ledger\Models\Event;
use RuntimeException;
use Tests\TestCase;

class MergePeopleActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem', 'intelligence'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_rewrites_leads_and_participants_fks_when_merging(): void
    {
        $source = $this->seedPeople('Andres', 'Pina');
        $target = $this->seedPeople('Andres', 'Pina');

        $lead = new LeadFactory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId(static::$cachedUser->getId())
            ->withPeopleId($source->id)
            ->create();

        $participantId = DB::connection('crm')->table('leads_participants')->insertGetId([
            'leads_id' => $lead->id,
            'peoples_id' => $source->id,
            'participants_types_id' => 1,
            'created_at' => Carbon::now(),
        ]);

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        $lead->refresh();
        $this->assertSame((int) $target->id, (int) $lead->people_id);
        $this->assertSame(
            (int) $target->id,
            (int) DB::connection('crm')->table('leads_participants')->where('id', $participantId)->value('peoples_id'),
        );

        $source->refresh();
        $this->assertTrue((bool) $source->is_deleted, 'source should be soft-deleted after merge.');
    }

    public function test_dedupes_organizations_peoples_when_target_already_has_the_same_org(): void
    {
        $source = $this->seedPeople('Andres', 'Pina');
        $target = $this->seedPeople('Andres', 'Pina');
        $sharedOrg = $this->seedOrganization('Shared Org');
        $sourceOnlyOrg = $this->seedOrganization('Source Only Org');

        // Pretend both source and target are already linked to $sharedOrg
        OrganizationPeople::create([
            'organizations_id' => $sharedOrg->id,
            'peoples_id' => $source->id,
            'created_at' => Carbon::now(),
        ]);
        OrganizationPeople::create([
            'organizations_id' => $sharedOrg->id,
            'peoples_id' => $target->id,
            'created_at' => Carbon::now(),
        ]);
        // Only source is linked to $sourceOnlyOrg — should rebind
        OrganizationPeople::create([
            'organizations_id' => $sourceOnlyOrg->id,
            'peoples_id' => $source->id,
            'created_at' => Carbon::now(),
        ]);

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(
            0,
            OrganizationPeople::query()->where('peoples_id', $source->id)->count(),
            'source pivot rows should be either rebound or deleted.',
        );
        $this->assertSame(
            2,
            OrganizationPeople::query()->where('peoples_id', $target->id)->count(),
            'target should be linked to both orgs (one pre-existing, one rebound from source).',
        );
    }

    public function test_rebinds_employment_history_to_target(): void
    {
        $source = $this->seedPeople('Andres', 'Pina');
        $target = $this->seedPeople('Andres', 'Pina');
        $org = $this->seedOrganization('Acme');

        $ehId = DB::connection('crm')->table('peoples_employment_history')->insertGetId([
            'apps_id' => $this->kanvasApp->getId(),
            'peoples_id' => $source->id,
            'organizations_id' => $org->id,
            'position' => 'Engineer',
            'start_date' => '2020-01-01',
            'end_date' => null,
            'status' => 1,
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(
            (int) $target->id,
            (int) DB::connection('crm')->table('peoples_employment_history')->where('id', $ehId)->value('peoples_id'),
            'employment history should follow the merge to the target people.',
        );
    }

    public function test_transfers_contacts_the_target_does_not_already_have(): void
    {
        $source = $this->seedPeople('Andres', 'Pina');
        $target = $this->seedPeople('Andres', 'Pina');

        $sharedEmail = 'shared-' . uniqid() . '@test.com';
        $target->addEmail($sharedEmail, 0, 0);
        $source->addEmail($sharedEmail, 0, 0); // duplicate of target's — should be dropped
        $sourceOnlyPhone = '809' . fake()->unique()->numerify('#######');
        $source->addPhone($sourceOnlyPhone, 0, 0); // unique to source — should transfer

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        $targetValues = $target->contacts()->pluck('value')->all();
        $this->assertContains($sourceOnlyPhone, $targetValues);
        $this->assertSame(
            1,
            $target->contacts()->where('value', $sharedEmail)->count(),
            'the duplicate email should not be duplicated on the target.',
        );
        $this->assertSame(
            0,
            $source->contacts()->count(),
            'source contacts should all be transferred or dropped as duplicates.',
        );
    }

    public function test_adopts_custom_fields_only_when_target_lacks_them(): void
    {
        $source = $this->seedPeople('Andres', 'Pina');
        $target = $this->seedPeople('Andres', 'Pina');

        $source->set('salesforce_contact_id', '003xxSOURCE');
        $target->set('salesforce_contact_id', '003xxTARGET'); // conflict — target already has one

        $source->set('some_other_field', 'adopt-me');

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        $target->refresh();
        $this->assertSame('003xxTARGET', $target->get('salesforce_contact_id'), 'a field both rows have is left as a conflict.');
        $this->assertSame('adopt-me', $target->get('some_other_field'), 'a field only source has is adopted onto target.');
    }

    public function test_records_audit_trail_on_merge(): void
    {
        $source = $this->seedPeople('Andres', 'Pina');
        $target = $this->seedPeople('Andres', 'Pina');

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();

        $source->refresh();
        $this->assertSame(
            (int) $target->id,
            (int) $source->merged_into_people_id,
            'the merged-away people record which survivor it became.',
        );

        $event = Event::query()
            ->where('event_type', 'guild.people.merged')
            ->where('source_entity_type', People::class)
            ->where('source_entity_id', (int) $target->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'a ledger event records the merge.');
        $payload = (array) $event->payload;
        $this->assertSame((int) $source->id, $payload['source_people_id']);
        $this->assertSame((int) $target->id, $payload['target_people_id']);
    }

    public function test_rejects_cross_tenant_merge(): void
    {
        $source = $this->seedPeople('Andres', 'Pina');

        $target = $this->seedPeople('Andres', 'Pina');
        $target->companies_id = $this->company->getId() + 9999;
        $target->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/across tenants/');

        new MergePeopleAction(
            source: $source,
            target: $target,
            user: static::$cachedUser,
        )->execute();
    }

    public function test_rejects_self_merge_and_deleted_source(): void
    {
        $samePeople = $this->seedPeople('Andres', 'Pina');

        $threwSelf = false;

        try {
            new MergePeopleAction(
                source: $samePeople,
                target: $samePeople,
                user: static::$cachedUser,
            )->execute();
        } catch (RuntimeException) {
            $threwSelf = true;
        }
        $this->assertTrue($threwSelf, 'merge into self should be rejected.');

        $deletedSource = $this->seedPeople('Andres', 'Deleted');
        $deletedSource->is_deleted = true;
        $deletedSource->save();
        $target = $this->seedPeople('Andres', 'Pina');

        $threwDeleted = false;

        try {
            new MergePeopleAction(
                source: $deletedSource,
                target: $target,
                user: static::$cachedUser,
            )->execute();
        } catch (RuntimeException) {
            $threwDeleted = true;
        }
        $this->assertTrue($threwDeleted, 'merging an already-deleted source should be rejected.');
    }

    private function seedPeople(string $firstname, string $lastname): People
    {
        return People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'name' => $firstname . ' ' . $lastname,
        ]);
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
