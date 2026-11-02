<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PeopleOptOutContactsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    public function testOnlyTheEmailContactsAreOptedOut(): void
    {
        $people = $this->people();
        foreach ([ContactTypeEnum::EMAIL, ContactTypeEnum::PRIMARY_EMAIL, ContactTypeEnum::SECONDARY_EMAIL] as $type) {
            $this->contact($people, $type, uniqid('p', true) . '@example.com');
        }
        $this->contact($people, ContactTypeEnum::CELLPHONE, '7875550101');
        $this->contact($people, ContactTypeEnum::WORK_PHONE, '7875550102');

        $changed = $people->optOutEmailContacts();

        $this->assertSame(3, $changed);
        $this->assertSame(0, $people->contacts()->whereIn('contacts_types_id', Contact::EMAIL_TYPES)->where('is_opt_out', 0)->count());
        $this->assertSame(0, $people->contacts()->whereIn('contacts_types_id', Contact::PHONE_TYPES)->where('is_opt_out', 1)->count());
    }

    public function testAnOptOutAlreadyHeldIsNotChangedAndNotCountedAgain(): void
    {
        $people = $this->people();
        $this->contact($people, ContactTypeEnum::EMAIL, 'held.' . uniqid() . '@example.com');

        $this->contact($people, ContactTypeEnum::CELLPHONE, '7875550103');

        $this->assertSame(1, $people->optOutEmailContacts());
        $this->assertSame(0, $people->optOutEmailContacts(), 'a second call changes nothing');
        $this->assertSame(1, $people->optOutPhoneContacts());
        $this->assertSame(0, $people->optOutPhoneContacts(), 'neither does a second phone call');
    }

    private function people(): People
    {
        return People::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => static::$cachedUser->getCurrentCompany()->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => 'Channel',
            'lastname' => 'OptOut',
            'name' => 'Channel OptOut',
        ]);
    }

    private function contact(People $people, ContactTypeEnum $type, string $value): Contact
    {
        return Contact::create([
            'peoples_id' => $people->getId(),
            'contacts_types_id' => $type->value,
            'value' => $value,
            'is_opt_out' => 0,
            'weight' => 0,
        ]);
    }
}
