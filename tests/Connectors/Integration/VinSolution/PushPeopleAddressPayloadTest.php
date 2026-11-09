<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushPeopleAction;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

final class PushPeopleAddressPayloadTest extends TestCase
{
    public function testContactReadsAddressesNestedUnderContactInformation(): void
    {
        $contact = new Contact($this->vinContactResponse([$this->vinAddress(777, 'Primary')]));

        $this->assertCount(1, $contact->addresses);
        $this->assertSame(777, $contact->addresses[0]['AddressId']);
    }

    public function testPeopleAddressesAreSentAsNew(): void
    {
        $people = $this->createPersonWithAddress();

        $prepared = $this->makeAction($people)->prepareAddressesForTest($people);

        $this->assertCount(1, $prepared);
        $this->assertSame(0, $prepared[0]['AddressId']);
        $this->assertSame('Primary', $prepared[0]['AddressType']);
        $this->assertSame('123 Main St', $prepared[0]['StreetAddress']);
        $this->assertSame('FL', $prepared[0]['State']);
    }

    public function testUpdateKeepsAddressIdZeroWhenVinHasNoAddress(): void
    {
        $people = $this->createPersonWithAddress();
        $action = $this->makeAction($people);
        $contact = new Contact($this->vinContactResponse([]));

        $contact->addresses = $action->alignAddressIdsForTest(
            $action->prepareAddressesForTest($people),
            $contact->addresses
        );
        $payload = $contact->buildUpdatePayload(1, 2);

        $this->assertSame(0, $payload['ContactInformation']['Addresses'][0]['AddressId']);
        $this->assertSame('123 Main St', $payload['ContactInformation']['Addresses'][0]['StreetAddress']);
    }

    public function testUpdateReusesVinAddressIdForTheSameAddressType(): void
    {
        $people = $this->createPersonWithAddress();
        $action = $this->makeAction($people);
        $contact = new Contact($this->vinContactResponse([
            $this->vinAddress(777, 'Primary'),
            $this->vinAddress(778, 'Previous'),
        ]));

        $contact->addresses = $action->alignAddressIdsForTest(
            $action->prepareAddressesForTest($people),
            $contact->addresses
        );
        $payload = $contact->buildUpdatePayload(1, 2);

        $this->assertCount(1, $payload['ContactInformation']['Addresses']);
        $this->assertSame(777, $payload['ContactInformation']['Addresses'][0]['AddressId']);
        $this->assertSame('123 Main St', $payload['ContactInformation']['Addresses'][0]['StreetAddress']);
    }

    public function testAlignAssignsEachVinAddressIdOnlyOnce(): void
    {
        $action = $this->makeAction($this->createPersonWithAddress());

        $aligned = $action->alignAddressIdsForTest(
            [
                ['AddressId' => 0, 'AddressType' => 'Primary', 'StreetAddress' => '1 A St'],
                ['AddressId' => 0, 'AddressType' => 'Primary', 'StreetAddress' => '2 B St'],
            ],
            [$this->vinAddress(777, 'Primary')]
        );

        $this->assertSame(777, $aligned[0]['AddressId']);
        $this->assertSame(0, $aligned[1]['AddressId']);
    }

    private function vinContactResponse(array $addresses): array
    {
        return [
            'ContactId' => 901,
            'ContactInformation' => [
                'FirstName' => 'Rebecca',
                'LastName' => 'Dago',
                'Emails' => [],
                'Phones' => [],
                'Addresses' => $addresses,
            ],
        ];
    }

    private function vinAddress(int $id, string $type): array
    {
        return [
            'AddressId' => $id,
            'AddressType' => $type,
            'StreetAddress' => '9 Old Rd',
            'City' => 'Tampa',
            'State' => 'FL',
            'PostalCode' => '33601',
        ];
    }

    private function createPersonWithAddress(): People
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

        $people->address()->create([
            'address' => '123 Main St',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33101',
            'is_default' => 1,
            'city_id' => 0,
            'state_id' => 0,
            'countries_id' => 0,
        ]);

        return $people->fresh();
    }

    private function makeAction(People $people): PushPeopleAction
    {
        // Bypass the parent constructor (it resolves live VinSolutions credentials); only the
        // payload-building logic is exercised here.
        return new class ($people) extends PushPeopleAction {
            public function __construct(People $people)
            {
                $this->people = $people;
            }

            public function prepareAddressesForTest(People $people): array
            {
                return $this->prepareAddresses($people);
            }

            public function alignAddressIdsForTest(array $addresses, array $existing): array
            {
                return $this->alignAddressIds($addresses, $existing);
            }
        };
    }
}
