<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

class DriverLicenseVerificationServiceTest extends TestCase
{
    public function testParseAddressMultilineWithComma(): void
    {
        $result = DriverLicenseVerificationService::parseAddress("123 Main St\nMiami, FL 33101");

        $this->assertSame(
            [
                'address' => '123 Main St',
                'city' => 'Miami',
                'state' => 'FL',
                'zipcode' => '33101',
            ],
            $result
        );
    }

    public function testParseAddressMultilineWithoutComma(): void
    {
        $result = DriverLicenseVerificationService::parseAddress("456 Oak Ave\nDallas TX 75201");

        $this->assertSame(
            [
                'address' => '456 Oak Ave',
                'city' => 'Dallas',
                'state' => 'TX',
                'zipcode' => '75201',
            ],
            $result
        );
    }

    public function testParseAddressSingleLineFullyCommaSeparated(): void
    {
        $result = DriverLicenseVerificationService::parseAddress('789 Pine Rd, Austin, TX 78701');

        $this->assertSame(
            [
                'address' => '789 Pine Rd',
                'city' => 'Austin',
                'state' => 'TX',
                'zipcode' => '78701',
            ],
            $result
        );
    }

    public function testParseAddressZipPlusFour(): void
    {
        $result = DriverLicenseVerificationService::parseAddress("100 Elm St\nMiami, FL 33101-1234");

        $this->assertNotNull($result);
        $this->assertSame('33101-1234', $result['zipcode']);
    }

    public function testParseAddressReturnsNullWhenUnparseable(): void
    {
        $this->assertNull(DriverLicenseVerificationService::parseAddress('totally not an address'));
    }

    public function testIsValidDate(): void
    {
        $this->assertTrue(DriverLicenseVerificationService::isValidDate('1990-05-15'));
        $this->assertTrue(DriverLicenseVerificationService::isValidDate('2024-12-31'));

        $this->assertFalse(DriverLicenseVerificationService::isValidDate('1990-13-01'));
        $this->assertFalse(DriverLicenseVerificationService::isValidDate('1990-02-30'));
    }

    public function testGetDefaultVerificationMessageValidNotExpired(): void
    {
        $message = DriverLicenseVerificationService::getDefaultVerificationMessage('John Doe', true, false);
        $this->assertSame('John Doe passed the ID Verification.', $message);
    }

    public function testGetDefaultVerificationMessageValidButExpired(): void
    {
        $message = DriverLicenseVerificationService::getDefaultVerificationMessage('John Doe', true, true);
        $this->assertSame('John Doe passed the ID Verification but the ID has expired.', $message);
    }

    public function testGetDefaultVerificationMessageInvalid(): void
    {
        $message = DriverLicenseVerificationService::getDefaultVerificationMessage('John Doe', false, false);
        $this->assertSame(
            "John Doe didn't pass the ID Verification due to ID check fail. Proceed with caution.",
            $message
        );
    }

    public function testGetVerificationMessagePrefersReportMessage(): void
    {
        $service = $this->makeService();
        $service->setIdVerificationReport([
            'status' => 'flag',
            'message' => 'Custom intellicheck verdict from report',
        ]);

        $people = new People();
        $people->name = 'Jane Doe';

        $this->assertSame(
            'Custom intellicheck verdict from report',
            $service->getVerificationMessage($people, true, false)
        );
    }

    public function testGetVerificationMessageFallsBackToDefaultWhenNoReport(): void
    {
        $service = $this->makeService();

        $people = new People();
        $people->name = 'Jane Doe';

        $this->assertSame(
            'Jane Doe passed the ID Verification.',
            $service->getVerificationMessage($people, true, false)
        );
    }

    public function testGetVerificationMessageFallsBackToDefaultWhenReportHasNoMessage(): void
    {
        $service = $this->makeService();
        $service->setIdVerificationReport(['status' => 'green']);

        $people = new People();
        $people->name = 'Jane Doe';

        $this->assertSame(
            'Jane Doe passed the ID Verification.',
            $service->getVerificationMessage($people, true, false)
        );
    }

    private function makeService(): DriverLicenseVerificationService
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new DriverLicenseVerificationService($app, $company, $user);
    }
}
