<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\Contact as ContactData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Traits\ManagesPeopleContactsTrait;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class PeopleContactSyncTest extends TestCase
{
    /**
     * Third-party syncs send the same phone in a different format every time
     * (country code, punctuation). The contact must be matched on its canonical
     * NANP form (last 10 digits) and updated in place — NOT deleted and recreated
     * with a fresh id on each sync.
     */
    public function testReformattedPhoneDoesNotRecreateContact(): void
    {
        $people = $this->createPersonWithContacts();
        $before = $people->contacts()->orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $before, 'fixture must start with 3 contacts');

        $this->syncWith($people, [
            new ContactData(value: 'snow@salesassist.io', contacts_types_id: ContactTypeEnum::EMAIL->value, weight: 100),
            new ContactData(value: '+1 (201) 123-4567', contacts_types_id: ContactTypeEnum::PHONE->value, weight: 100),
            new ContactData(value: '650-385-9777', contacts_types_id: ContactTypeEnum::CELLPHONE->value, weight: 3),
        ]);

        $after = $people->fresh()->contacts()->orderBy('id')->pluck('id')->all();

        $this->assertSame(
            $before,
            $after,
            'contact ids must be preserved when the provider reformats the same phone numbers'
        );
    }

    public function testNormalizeValueCanonicalizesNanpPhone(): void
    {
        $this->assertSame(
            '2011234567',
            Contact::normalizeValue('+1 (201) 123-4567', ContactTypeEnum::PHONE->value),
            'a +1 country code and punctuation must canonicalize to the bare 10-digit number'
        );
        $this->assertSame(
            '2011234567',
            Contact::normalizeValue('2011234567', ContactTypeEnum::CELLPHONE->value),
            'an already-bare number stays unchanged'
        );
    }

    /**
     * A genuinely new contact is added; one absent from the incoming set is removed.
     */
    public function testAddsNewAndRemovesMissingContacts(): void
    {
        $people = $this->createPersonWithContacts();
        $keptEmailId = (int) $people->contacts()
            ->where('contacts_types_id', ContactTypeEnum::EMAIL->value)
            ->value('id');

        $this->syncWith($people, [
            new ContactData(value: 'snow@salesassist.io', contacts_types_id: ContactTypeEnum::EMAIL->value, weight: 100),
            new ContactData(value: '7185551234', contacts_types_id: ContactTypeEnum::PHONE->value, weight: 50),
        ]);

        $contacts = $people->fresh()->contacts()->get();

        $this->assertCount(2, $contacts, 'removed contacts must be deleted, new one added');
        $this->assertTrue($contacts->contains('id', $keptEmailId), 'matched email keeps its id');
        $this->assertTrue($contacts->contains('value', '7185551234'), 'new phone is added');
    }

    /**
     * Two overlapping syncs for the same person must not shred each other's rows. While one
     * holds the per-person lock, a second one skips instead of deleting + recreating contacts.
     */
    public function testSyncSkipsWhileAnotherHoldsThePersonLock(): void
    {
        $people = $this->createPersonWithContacts();
        $before = $people->contacts()->orderBy('id')->pluck('id')->all();

        $lock = Cache::lock('people-contacts-sync:' . $people->getKey(), 10);
        $this->assertTrue($lock->get(), 'precondition: acquire the per-person lock');

        try {
            $this->syncWith($people, [
                new ContactData(value: 'changed@salesassist.io', contacts_types_id: ContactTypeEnum::EMAIL->value, weight: 100),
            ]);
        } finally {
            $lock->release();
        }

        $this->assertSame(
            $before,
            $people->fresh()->contacts()->orderBy('id')->pluck('id')->all(),
            'a sync must skip (touch nothing) while another holds the per-person lock'
        );
    }

    private function syncWith(People $people, array $contacts): void
    {
        $runner = new class () {
            use ManagesPeopleContactsTrait;

            public function run(People $people, DataCollection $contacts): void
            {
                $this->syncContactsForUpdate($people, $contacts);
            }
        };

        $runner->run($people, new DataCollection(ContactData::class, $contacts));
    }

    private function createPersonWithContacts(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $people->contacts()->delete();
        $people->addEmail('snow@salesassist.io', 0, 100);
        $people->addPhone('2011234567', 0, 100);
        $people->addCellPhone('6503859777', 0, 3);

        return $people->fresh();
    }
}
