<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleDriverLicenseAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\DataTransferObject\Contact as ContactData;
use Kanvas\Guild\Customers\DataTransferObject\DriverLicense;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class PeopleDriverLicenseTest extends TestCase
{
    public function testFromScanNormalizesTheScannerPayload(): void
    {
        $license = DriverLicense::fromScan($this->scan());

        $this->assertNotNull($license);
        $this->assertSame('0000-00-0000', $license->number);
        $this->assertSame('IN', $license->state);
        $this->assertSame('2030-01-01', $license->expirationDate?->toDateString());
        $this->assertSame('1990-01-01', $license->dob?->toDateString());
        $this->assertSame('TEST', $license->firstname);
        $this->assertSame('USER', $license->lastname);
    }

    /**
     * A scanner that could not read a date emits zero-filled parts; those must not become
     * a bogus year-0 Carbon on the People row.
     */
    public function testFromScanDropsZeroFilledDates(): void
    {
        $license = DriverLicense::fromScan([
            'license' => 'X123',
            'state' => 'FL',
            'birthday' => ['day' => 0, 'month' => 0, 'year' => 0],
            'exp_date' => ['day' => 0, 'month' => 0, 'year' => 0],
        ]);

        $this->assertNotNull($license);
        $this->assertNull($license->expirationDate);
        $this->assertNull($license->dob);
    }

    public function testFromScanReturnsNullWithoutALicenseNumber(): void
    {
        $this->assertNull(DriverLicense::fromScan(null));
        $this->assertNull(DriverLicense::fromScan([]));
        $this->assertNull(DriverLicense::fromScan(['state' => 'IN', 'license' => '']));
    }

    public function testGetDriverLicenseReadsThePeopleRowBeforeTheCustomField(): void
    {
        $people = $this->makePeople();
        $people->license_number = 'COLUMN-1';
        $people->license_expiration_date = '2031-06-30';
        $people->license_state = 'FL';
        $people->saveOrFail();

        $people->set('get_docs_drivers_license', $this->scan());

        $license = $people->fresh()->getDriverLicense();

        $this->assertNotNull($license);
        $this->assertSame('COLUMN-1', $license->number, 'the column wins over the legacy custom field');
        $this->assertSame('2031-06-30', $license->expirationDate?->toDateString());
        $this->assertSame('FL', $license->state, 'the state column wins over the legacy scan');
    }

    public function testGetDriverLicenseFallsBackToTheDefaultAddressForState(): void
    {
        $people = $this->makePeople();
        $people->license_number = 'COLUMN-1';
        $people->saveOrFail();
        $people->addDefaultAddress(AddressData::from([
            'address' => '123 Test St',
            'city' => 'Sample City',
            'state' => 'tx',
            'zip' => '73301',
        ]));

        $this->assertSame('TX', $people->fresh()->getDriverLicense()?->state);
    }

    public function testNormalizeStateAcceptsBareStringsAndCodeArrays(): void
    {
        $this->assertSame('FL', DriverLicense::normalizeState('fl'));
        $this->assertSame('FL', DriverLicense::normalizeState(' fl '));
        $this->assertSame('FL', DriverLicense::normalizeState(['code' => 'FL']));
        $this->assertNull(DriverLicense::normalizeState(''));
        $this->assertNull(DriverLicense::normalizeState(null));
        $this->assertNull(DriverLicense::normalizeState(['name' => 'Florida']));
        $this->assertNull(DriverLicense::normalizeState('Florida'), 'a full state name is not a code');
    }

    public function testGetDriverLicenseFallsBackToTheLegacyCustomField(): void
    {
        $people = $this->makePeople();
        $people->set('get_docs_drivers_license', $this->scan());

        $license = $people->fresh()->getDriverLicense();

        $this->assertNotNull($license);
        $this->assertSame('0000-00-0000', $license->number);
        $this->assertSame('2030-01-01', $license->expirationDate?->toDateString());
    }

    public function testGetDriverLicenseIsNullWhenNothingIsOnFile(): void
    {
        $this->assertNull($this->makePeople()->getDriverLicense());
        $this->assertNull($this->makePeople()->getDriverLicenseData());
    }

    /**
     * Regression: an unrelated people sync (Shopify/Apollo/NetSuite/Twilio/GraphQL patch)
     * builds the DTO without license fields, which used to null the scanned license out.
     */
    public function testPartialUpdateDoesNotWipeTheLicense(): void
    {
        $people = $this->makePeople();
        $people->license_number = 'KEEP-ME';
        $people->license_expiration_date = '2031-06-30';
        $people->license_state = 'FL';
        $people->dob = '1985-02-03';
        $people->saveOrFail();

        new UpdatePeopleAction(
            $people,
            new PeopleData(
                app: app(Apps::class),
                branch: $people->company->defaultBranch,
                user: auth()->user(),
                firstname: 'Renamed',
                contacts: ContactData::collect([], DataCollection::class),
                address: AddressData::collect([], DataCollection::class),
                id: $people->getId(),
            ),
        )->execute();

        $fresh = $people->fresh();

        $this->assertSame('Renamed', $fresh->firstname);
        $this->assertSame('KEEP-ME', $fresh->license_number);
        $this->assertSame('2031-06-30', $fresh->license_expiration_date->format('Y-m-d'));
        $this->assertSame('FL', $fresh->license_state);
        $this->assertSame('1985-02-03', $fresh->dob->format('Y-m-d'));
    }

    public function testUpdatePeopleDriverLicenseActionDoesNotOverwriteAScannedLicense(): void
    {
        $people = $this->makePeople();
        $people->license_number = 'SCANNED';
        $people->saveOrFail();

        new UpdatePeopleDriverLicenseAction(
            $people,
            new DriverLicense(number: 'SELF-REPORTED', state: 'FL'),
        )->execute();

        $this->assertSame('SCANNED', $people->fresh()->license_number);
    }

    public function testUpdatePeopleDriverLicenseActionFillsAnEmptyLicense(): void
    {
        $people = $this->makePeople();

        new UpdatePeopleDriverLicenseAction(
            $people,
            new DriverLicense(number: 'SELF-REPORTED', state: 'FL'),
        )->execute();

        $fresh = $people->fresh();
        $this->assertSame('SELF-REPORTED', $fresh->license_number);
        $this->assertSame('FL', $fresh->license_state);
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(): array
    {
        return [
            'address' => '123 TEST ST, SAMPLE CITY, IN, 46327',
            'license' => '0000-00-0000',
            'state' => 'IN',
            'birthday' => ['day' => 1, 'month' => 1, 'year' => 1990],
            'exp_date' => ['day' => 1, 'month' => 1, 'year' => 2030],
            'firstname' => 'TEST',
            'middlename' => 'SAMPLE',
            'lastname' => 'USER',
        ];
    }

    private function makePeople(): People
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

        return $people;
    }
}
