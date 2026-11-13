<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Tests\TestCase;

/**
 * Both fixtures are trimmed from real mobile scans of the same license: one whose barcode read, one whose
 * back was rejected as blurry and arrived with nothing under `idcheck.data` but `processResult`.
 */
final class DriverLicenseScanTest extends TestCase
{
    public function testARejectedBarcodeFallsBackToTheFrontOcr(): void
    {
        $scan = IdVerificationService::toDriverLicenseScan([
            'idcheck' => ['data' => ['processResult' => 'DocumentBadDevice'], 'success' => false],
            'OCR' => ['data' => $this->ocr()],
        ]);

        $this->assertSame('051330477', $scan['license']);
        $this->assertSame('Jowsmilk', $scan['firstname'], "OCR's firstName carries the full name");
        $this->assertSame('Perez', $scan['lastname']);
        $this->assertSame(['day' => 14, 'month' => 9, 'year' => 1980], $scan['birthday']);
        $this->assertSame(['day' => 14, 'month' => 9, 'year' => 2032], $scan['exp_date']);
        $this->assertSame('115 Flint Hill Dr, Oxford, GA 30054-3928', $scan['address']);
        $this->assertSame('GA', $scan['state']);
    }

    public function testAReadBarcodeKeepsItsExpirationAndAddress(): void
    {
        $scan = IdVerificationService::toDriverLicenseScan([
            'idcheck' => ['data' => $this->barcode()],
            'OCR' => ['data' => $this->ocr()],
        ]);

        $this->assertSame(['day' => 14, 'month' => 9, 'year' => 2032], $scan['exp_date']);
        $this->assertSame('115 Flint Hill Dr, Oxford, GA 30054-3928', $scan['address']);
        $this->assertSame('GA', $scan['state']);
        $this->assertSame('Jowsmilk', $scan['firstname']);
    }

    public function testTheBarcodeWinsOverTheOcrFieldByField(): void
    {
        $scan = IdVerificationService::toDriverLicenseScan([
            'idcheck' => ['data' => ['dLIDNumberRaw' => 'BARCODE-1', 'firstName' => 'Barcode']],
            'OCR' => ['data' => $this->ocr()],
        ]);

        $this->assertSame('BARCODE-1', $scan['license']);
        $this->assertSame('Barcode', $scan['firstname']);
        $this->assertSame('Perez', $scan['lastname'], 'a field the barcode lacks still comes from OCR');
    }

    public function testAnOcrAddressWithATrailingCommaStillYieldsTheState(): void
    {
        $scan = IdVerificationService::toDriverLicenseScan([
            'OCR' => ['data' => ['address' => '115 Flint Hill Dr, Oxford, GA 30054-3928,'] + $this->ocr()],
        ]);

        $this->assertSame('GA', $scan['state']);
    }

    public function testNothingToReadReturnsNull(): void
    {
        $this->assertNull(IdVerificationService::toDriverLicenseScan([]));
        $this->assertNull(IdVerificationService::toDriverLicenseScan([
            'idcheck' => ['data' => ['processResult' => 'DocumentBadDevice']],
            'OCR' => ['data' => ['documentRecognized' => 0]],
        ]));
    }

    private function barcode(): array
    {
        return [
            'processResult' => 'DocumentProcessOK',
            'firstName' => 'Jowsmilk',
            'lastName' => 'Perez',
            'address1' => '115 Flint Hill Dr',
            'city' => 'Oxford',
            'state' => 'GA',
            'postalCode' => '30054-3928',
            'dateOfBirth' => '09/14/1980',
            'dLIDNumberRaw' => '051330477',
            'expirationDate' => '09/14/2032',
        ];
    }

    private function ocr(): array
    {
        return [
            'firstName' => 'Jowsmilk Perez',
            'lastName' => 'Perez',
            'fullName' => 'Jowsmilk Perez',
            'documentNumber' => '051330477',
            'dateOfBirth' => '1980-09-14',
            'dateOfExpiry' => '2032-09-14',
            'issuerName' => 'Georgia',
            'address' => '115 Flint Hill Dr, Oxford, GA 30054-3928',
        ];
    }
}
