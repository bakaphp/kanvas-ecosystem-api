<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ManagePersonContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\MarkPersonDoNotContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetPersonAddressTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdatePersonTool;
use Kanvas\Locations\Models\Countries;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class PersonProfileToolsTest extends TestCase
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

    public function test_manage_contact_saves_work_phone_and_secondary_email(): void
    {
        $person = $this->makePerson();

        $phone = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            value: '+18095550101',
            kind: 'work_phone',
        );
        $email = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            value: 'second-' . uniqid() . '@x.test',
            kind: 'secondary_email',
        );

        $this->assertTrue($phone['success']);
        $this->assertSame('work_phone', $phone['contact']['kind']);
        $this->assertSame('secondary_email', $email['contact']['kind']);
        $this->assertSame(2, $person->contacts()->count());
    }

    public function test_manage_contact_saves_linkedin_and_get_person_reads_it(): void
    {
        $person = $this->makePerson();

        $result = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            value: 'https://www.linkedin.com/in/someone',
            kind: 'linkedin',
        );
        $this->assertSame('linkedin', $result['contact']['kind']);

        $profile = $this->tool(new GetPersonTool())->__invoke(person_id: (int) $person->getId());
        $this->assertSame('https://www.linkedin.com/in/someone', $profile['linkedin']);
        $this->assertSame([], $profile['phones']);
    }

    public function test_manage_contact_saving_a_formatted_phone_twice_does_not_duplicate(): void
    {
        $person = $this->makePerson();

        foreach (['+1 (809) 555-0142', '18095550142'] as $value) {
            $this->tool(new ManagePersonContactTool())->__invoke(
                person_id: (int) $person->getId(),
                value: $value,
                kind: 'cellphone',
            );
        }

        $this->assertSame(1, $person->contacts()->count());
    }

    public function test_manage_contact_marks_invalid_then_valid(): void
    {
        $person = $this->makePerson();
        $value = 'verify-' . uniqid() . '@x.test';
        $person->addEmail($value);

        $invalid = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            value: $value,
            action: 'mark_invalid',
        );
        $this->assertSame(ContactValidationStatusEnum::INVALID->value, $invalid['contact']['validation_status']);

        $valid = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            value: $value,
            action: 'mark_valid',
        );
        $this->assertSame(ContactValidationStatusEnum::VALID->value, $valid['contact']['validation_status']);
    }

    public function test_manage_contact_removes_a_wrong_value(): void
    {
        $person = $this->makePerson();
        $person->addCellPhone('+1 809 555 0199');

        $result = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            value: '+18095550199',
            action: 'remove',
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $person->contacts()->where('is_deleted', 0)->count());
    }

    public function test_manage_contact_on_unknown_value_changes_nothing(): void
    {
        $person = $this->makePerson();

        $result = $this->tool(new ManagePersonContactTool())->__invoke(
            person_id: (int) $person->getId(),
            value: 'nobody@x.test',
            action: 'remove',
        );

        $this->assertFalse($result['success'] ?? false);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_update_person_sets_people_type(): void
    {
        $person = $this->makePerson();
        $type = PeopleType::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => 'Facilitator' . uniqid(),
        ]);

        $result = $this->tool(new UpdatePersonTool())->__invoke(
            person_id: (int) $person->getId(),
            people_type: $type->name,
        );

        $this->assertSame($type->name, $result['people_type']);
        $person->refresh();
        $this->assertSame($type->getId(), (int) $person->people_types_id);
    }

    public function test_update_person_rejects_unknown_people_type(): void
    {
        $person = $this->makePerson();

        $result = $this->tool(new UpdatePersonTool())->__invoke(
            person_id: (int) $person->getId(),
            people_type: 'NoSuchType' . uniqid(),
            firstname: 'Shouldnotchange',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertNotSame('Shouldnotchange', $person->refresh()->firstname);
    }

    public function test_set_address_adds_then_patches_in_place(): void
    {
        $person = $this->makePerson();
        $country = Countries::firstOrCreate(['code' => 'DO'], ['name' => 'Dominican Republic']);

        $added = $this->tool(new SetPersonAddressTool())->__invoke(
            person_id: (int) $person->getId(),
            address: 'Av. Winston Churchill 1',
            city: 'Santo Domingo',
            zip: '10101',
            country: $country->name,
            is_default: true,
        );
        $this->assertTrue($added['success']);
        $addressId = $added['address']['address_id'];

        $patched = $this->tool(new SetPersonAddressTool())->__invoke(
            person_id: (int) $person->getId(),
            address_id: $addressId,
            zip: '10148',
        );

        $this->assertSame($addressId, $patched['address']['address_id']);
        $this->assertSame('10148', $patched['address']['zip']);
        $this->assertSame('Av. Winston Churchill 1', $patched['address']['address']);
        $this->assertSame($country->name, $patched['address']['country']);
        $this->assertSame(1, $person->address()->count());
    }

    public function test_set_address_requires_street_when_adding(): void
    {
        $person = $this->makePerson();

        $result = $this->tool(new SetPersonAddressTool())->__invoke(person_id: (int) $person->getId(), city: 'Santiago');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, $person->address()->count());
    }

    public function test_mark_do_not_contact_opts_out_every_contact(): void
    {
        $person = $this->makePerson();
        $person->addEmail('dnc-' . uniqid() . '@x.test');
        $person->addCellPhone('+18095550177');

        $result = $this->tool(new MarkPersonDoNotContactTool())->__invoke(
            person_id: (int) $person->getId(),
            reason: 'Asked the account manager by phone',
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['contacts_opted_out']);
        $this->assertTrue((bool) $person->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertSame(0, $person->contacts()->where('is_opt_out', 0)->count());

        $again = $this->tool(new MarkPersonDoNotContactTool())->__invoke(person_id: (int) $person->getId());
        $this->assertSame('noop', $again['outcome']);
    }

    public function test_get_person_returns_addresses_type_and_contact_kinds(): void
    {
        $person = $this->makePerson();
        $person->addContact(ContactTypeEnum::WORK_PHONE->getName(), '+18095550123');
        $this->tool(new SetPersonAddressTool())->__invoke(person_id: (int) $person->getId(), address: 'Calle 1');

        $result = $this->tool(new GetPersonTool())->__invoke(person_id: (int) $person->getId());

        $this->assertSame('Work Phone', $result['phones'][0]['type']);
        $this->assertSame('Calle 1', $result['addresses'][0]['address']);
        $this->assertFalse($result['do_not_contact']);
        $this->assertArrayHasKey('people_type', $result);
    }

    private function tool(object $tool): object
    {
        return $tool->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function makePerson(): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => 'Profileuniq' . uniqid(), 'lastname' => 'Tools']);
    }
}
