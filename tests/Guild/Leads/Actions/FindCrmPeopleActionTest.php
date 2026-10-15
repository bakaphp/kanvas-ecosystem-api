<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Services\ReynoldsCrmDriver;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\FindCrmPeopleAction;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\DataTransferObject\CrmPeopleMatch;
use Kanvas\Guild\Leads\Enums\CrmPeopleMatchEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FindCrmPeopleActionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * ecosystem is not optional: $people->set() writes apps_custom_fields on that connection.
     */
    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testFindsThePeopleByTheCrmClientId(): void
    {
        $clientId = $this->uniqueId();
        $people = $this->createPeople('Ana', 'Perez', clientId: $clientId);

        $match = $this->find(['client_id' => $clientId]);

        $this->assertSame($people->getId(), $match->people->getId());
        $this->assertSame(CrmPeopleMatchEnum::CLIENT_ID, $match->matchedBy);
        $this->assertFalse($match->ambiguous);
    }

    public function testTheClientIdWinsOverANameMatch(): void
    {
        $clientId = $this->uniqueId();
        $email = $this->uniqueEmail();
        $this->createPeople('Ana', 'Perez', emails: [$email]);
        $byId = $this->createPeople('Someone', 'Else', clientId: $clientId);

        $match = $this->find(
            ['client_id' => $clientId],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'emails' => [$email]]
        );

        $this->assertSame($byId->getId(), $match->people->getId());
    }

    public function testFindsByNameAndEmailIgnoringCaseAndSpacing(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana  Maria', 'Perez', emails: [$email]);

        $match = $this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => '  ANA MARIA ', 'lastname' => 'perez', 'emails' => [strtoupper($email)]]
        );

        $this->assertSame($people->getId(), $match->people->getId());
        $this->assertSame(CrmPeopleMatchEnum::NAME_AND_CONTACT, $match->matchedBy);
    }

    /**
     * Focus sends ten digits; the stored number carries the country code.
     */
    #[DataProvider('storedPhones')]
    public function testFindsByNameAndAPhoneWhateverTheStoredCountryCode(string $stored): void
    {
        $people = $this->createPeople('Ana', 'Perez', phones: [$stored]);

        $match = $this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'phones' => ['cell' => '7875550101']]
        );

        $this->assertSame($people->getId(), $match?->people->getId());
    }

    public static function storedPhones(): array
    {
        return [
            'ten digits' => ['7875550101'],
            'formatted' => ['(787) 555-0101'],
            'plus one' => ['+17875550101'],
            'one prefix' => ['17875550101'],
        ];
    }

    public function testANameAloneIsNotAMatch(): void
    {
        $this->createPeople('Ana', 'Perez', emails: [$this->uniqueEmail()]);

        $this->assertNull($this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => 'Ana', 'lastname' => 'Perez']
        ));
    }

    public function testAContactAloneIsNotAMatch(): void
    {
        $email = $this->uniqueEmail();
        $this->createPeople('Someone', 'Else', emails: [$email]);

        $this->assertNull($this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'emails' => [$email]]
        ));
    }

    public function testBothNamesAreRequiredToSearch(): void
    {
        $email = $this->uniqueEmail();
        $this->createPeople('Ana', 'Perez', emails: [$email]);

        $this->assertNull($this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => 'Ana', 'lastname' => null, 'emails' => [$email]]
        ));
        $this->assertNull($this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => null, 'lastname' => 'Perez', 'emails' => [$email]]
        ));
    }

    public function testNothingIsFoundWithoutAnyContact(): void
    {
        $this->createPeople('Ana', 'Perez', emails: [$this->uniqueEmail()]);

        $this->assertNull($this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'emails' => [], 'phones' => ['cell' => null, 'home' => null, 'work' => null]]
        ));
    }

    public function testAPersonWithAnotherClientIdIsSomebodyElse(): void
    {
        $email = $this->uniqueEmail();
        $this->createPeople('Ana', 'Perez', emails: [$email], clientId: $this->uniqueId());

        $this->assertNull($this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'emails' => [$email]]
        ));
    }

    public function testAPersonWithTheSameClientIdOrNoneIsTheCustomer(): void
    {
        $clientId = $this->uniqueId();
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', emails: [$email]);

        $match = $this->find(
            ['client_id' => $clientId],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'emails' => [$email]]
        );

        $this->assertSame($people->getId(), $match->people->getId(), 'no client id stored yet');
    }

    public function testSeveralCandidatesGiveTheOldestAndSayItWasAmbiguous(): void
    {
        $email = $this->uniqueEmail();
        $oldest = $this->createPeople('Ana', 'Perez', emails: [$email]);
        $this->createPeople('Ana', 'Perez', emails: [$email]);

        $match = $this->find(
            ['client_id' => $this->uniqueId()],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'emails' => [$email]]
        );

        $this->assertSame($oldest->getId(), $match->people->getId());
        $this->assertTrue($match->ambiguous);
    }

    public function testAnotherCustomersIdDoesNotCountAmongTheCandidates(): void
    {
        $clientId = $this->uniqueId();
        $email = $this->uniqueEmail();
        $this->createPeople('Ana', 'Perez', emails: [$email], clientId: $this->uniqueId());
        $free = $this->createPeople('Ana', 'Perez', emails: [$email]);

        $match = $this->find(
            ['client_id' => $clientId],
            ['firstname' => 'Ana', 'lastname' => 'Perez', 'emails' => [$email]]
        );

        $this->assertSame($free->getId(), $match->people->getId());
        $this->assertFalse($match->ambiguous);
    }

    private function find(array $identity, ?array $customer = null): ?CrmPeopleMatch
    {
        $user = auth()->user();
        $data = CrmLeadSync::from(
            app(Apps::class),
            $user,
            $user->getCurrentCompany(),
            array_filter([
                'request_id' => 'req-' . uniqid(),
                'identity' => array_merge(['type' => 'reynolds', 'lead_id' => null], $identity),
                'customer' => $customer,
            ], fn (mixed $value): bool => $value !== null)
        );

        return new FindCrmPeopleAction($data, new ReynoldsCrmDriver())->execute();
    }

    private function createPeople(
        string $firstname,
        string $lastname,
        array $emails = [],
        array $phones = [],
        ?string $clientId = null
    ): People {
        $user = auth()->user();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['firstname' => $firstname, 'lastname' => $lastname]);

        foreach ($emails as $email) {
            $people->contacts()->create(['contacts_types_id' => 1, 'value' => $email, 'weight' => 0]);
        }

        foreach ($phones as $phone) {
            $people->contacts()->create(['contacts_types_id' => 3, 'value' => $phone, 'weight' => 0]);
        }

        if ($clientId !== null) {
            $people->set(CustomFieldEnum::CLIENT_ID->value, $clientId);
        }

        return $people;
    }

    private function uniqueId(): string
    {
        return (string) random_int(1000000, 9999999);
    }

    private function uniqueEmail(): string
    {
        return 'crm.' . uniqid() . '@example.com';
    }
}
