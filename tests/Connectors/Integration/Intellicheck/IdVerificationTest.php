<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intellicheck;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Services\IdVerificationService;
use Kanvas\Connectors\Intellicheck\Services\PeopleService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class IdVerificationTest extends TestCase
{
    protected function idVerificationData(): array
    {
        return [
            'is_showroom' => true,
            'idcheck' => [
                'data' => [
                    'uniqueID' => 2119,
                    'weightPounds' => null,
                    'heightFeetInches' => "5' 7\"",
                    'age' => 40,
                    'driverClass' => 'E',
                    'restrictions' => 'A',
                    'address1' => '10405 Sw 112th St',
                    'dLIDNumberRaw' => 'K523503856060',
                    'endorsements' => null,
                    'address2' => null,
                    'organDonor' => 'Yes',
                    'socialSecurity' => null,
                    'city' => 'Miami',
                    'issueDate' => '03/22/2006',
                    'transactionIdentifier' => 'ad1014e4-50f3-49db-a35c-57a884311814',
                    'processResult' => 'DocumentUnknown',
                    'gender' => 'Female',
                    'isDuplicate' => 'No',
                    'firstName' => 'Keira',
                    'issuingJurisdictionAbbrv' => 'FL',
                    'middleName' => 'Christina',
                    'eyeColor' => null,
                    'expired' => 'Yes',
                    'docType' => null,
                    'hairColor' => null,
                    'expirationDate' => '03/26/2012',
                    'extendedResultCode' => 'U',
                    'docCategory' => 'DL',
                    'stateIssuerMismatch' => 'No',
                    'heightCentimeters' => '170',
                    'duplicateDate' => null,
                    'lastName' => 'Knightley',
                    'issuingJurisdictionCvt' => 'Florida',
                    'dLIDNumberFormatted' => 'K523-503-85-606-0',
                    'postalCode' => '33176-3425',
                    'state' => 'FL',
                    'dateOfBirth' => '03/26/1985',
                    'isRealID' => null,
                    'mediaType' => '2D',
                    'testCard' => false,
                    'weightKilograms' => null,
                ],
                'result' => true,
                'success' => true,
                'message' => null,
            ],
            'OCR' => [
                'result' => true,
                'data' => [
                    'documentNumber' => 'W730421894796',
                    'eyeColor' => null,
                    'weightKilograms' => null,
                    'firstName' => 'WARNER ALVIN',
                    'dateOfBirth' => '1988-06-23',
                    'dlEndorsement' => null,
                    'faceImageBase64' => null,
                    'dateOfBirthFormatted' => '06/23/1988',
                    'errorMessage' => null,
                    'dateOfExpiryFormatted' => '12/29/2018',
                    'countryCode' => 'USA',
                    'dateOfExpiry' => '2018-12-29',
                    'dateOfIssue' => '2011-06-25',
                    'dlClass' => null,
                    'fullDocumentImageBase64' => null,
                    'dlRestrictions' => null,
                    'placeOfBirth' => null,
                    'age' => null,
                    'dateOfIssueFormatted' => '06/25/2011',
                    'address' => '2537 WAYMAN RD MOORE HAVEN, FL 33471-0000',
                    'fullName' => 'WAH WARNER ALVIN',
                    'isRealID' => 'yes',
                    'sex' => 'X',
                    'documentRecognized' => 1,
                    'lastName' => 'WAH',
                    'nationality' => null,
                    'issuerName' => 'Florida',
                    'height' => '180',
                ],
                'message' => null,
                'success' => true,
            ],
            'ocr_match' => [
                'data' => [
                    'addressMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                        'similarityScore' => 27,
                    ],
                    'isDocumentNumberMatch' => false,
                    'sexMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 0,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'isDobMatch' => true,
                    'documentNumberMatchDetails' => [
                        'similarityScore' => 7,
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'isCountryCodeMatch' => null,
                    'dlClassMatchDetails' => [
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                        'similarityThreshold' => 70,
                    ],
                    'dobMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => 70,
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                    ],
                    'issuerNameMatchDetails' => [
                        'similarityScore' => 100,
                        'details' => 'Exact match between barcode and front data',
                        'similarityThreshold' => 100,
                    ],
                    'isIssuerNameMatch' => true,
                    'dlRestrictionsMatchDetails' => [
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                    ],
                    'weightMatchDetails' => [
                        'details' => 'Missing value for comparison',
                        'similarityScore' => null,
                        'similarityThreshold' => 70,
                    ],
                    'issueDateMatchDetails' => [
                        'similarityScore' => 60,
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'isWeightMatch' => null,
                    'isIssueDateMatch' => false,
                    'realIdMatchDetails' => [
                        'similarityScore' => null,
                        'details' => 'Missing value for comparison',
                        'similarityThreshold' => 70,
                    ],
                    'eyeColorMatchDetails' => [
                        'details' => 'Missing value for comparison',
                        'similarityThreshold' => 70,
                        'similarityScore' => null,
                    ],
                    'dlEndorsementMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Missing value for comparison',
                        'similarityScore' => null,
                    ],
                    'isDlRestrictionsMatch' => null,
                    'isSexMatch' => false,
                    'isNationalityMatch' => null,
                    'isDlEndorsementMatch' => null,
                    'isRealIdMatch' => null,
                    'expirationDateMatchDetails' => [
                        'similarityScore' => 60,
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                    ],
                    'nameMatchDetails' => [
                        'similarityThreshold' => 70,
                        'details' => 'Mismatch, similarity score is lower than similarity threshold',
                        'similarityScore' => 21,
                    ],
                    'isNameMatch' => false,
                    'heightMatchDetails' => [
                        'details' => 'Considered match with slight discrepancy in either barcode and/or front data',
                        'similarityScore' => 75,
                        'similarityThreshold' => 70,
                    ],
                    'isExpirationDateMatch' => false,
                    'isHeightMatch' => true,
                    'isAddressMatch' => false,
                    'isDlClassMatch' => null,
                    'isEyeColorMatch' => null,
                ],
                'success' => false,
                'message' => null,
                'result' => true,
            ],
            'ip' => '172.18.0.24',
        ];
    }

    public function testIdVerificationShowroom()
    {
        $verificationData = $this->idVerificationData();

        $isShowRoom = $params['is_showroom'] ?? false;
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        // Get person name from lead entity
        $name = $lead->people->name;

        $verificationResults = IdVerificationService::processVerificationData($verificationData, $name, $isShowRoom);

        $this->assertEquals('fail', $verificationResults['status']);
        $this->assertArrayHasKey('flags', $verificationResults);
        $this->assertArrayHasKey('failures', $verificationResults);
        $this->assertArrayHasKey('results', $verificationResults);
        $this->assertArrayHasKey('message', $verificationResults);
        $this->assertArrayHasKey('ocMatch', $verificationResults);
        $this->assertArrayHasKey('status', $verificationResults);
    }

    public function testUpdatePeopleInfo()
    {
        $verificationData = $this->idVerificationData();

        $isShowRoom = $params['is_showroom'] ?? false;
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $name = $lead->people->name;

        // Update people info
        PeopleService::updatePeopleInformation($lead->people, $verificationData);

        // Assert that the lead's people info has been updated
        $this->assertEquals('Keira Knightley', People::getById($lead->people->getId())->name);
    }
}
