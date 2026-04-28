<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\ProcessLeadDriverLicenseVerificationAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Tests\TestCase;

class ProcessLeadDriverLicenseVerificationActionTest extends TestCase
{
    public function testReturnsEarlyWhenNoDriverLicenseImage(): void
    {
        $lead = $this->makeLead();

        $result = $this->makeAction($lead)->execute();

        $this->assertTrue($result['success']);
        $this->assertSame('No Driver License Image found to process', $result['message']);
        $this->assertSame([], $result['results']);
    }

    /**
     * Regression: notification + cleanup must fire even without driver license
     * images when an intellicheck report is available. Previously a premature
     * early return skipped both.
     */
    public function testNoImagesButIntellicheckResponsePresentStillReportsAndCleansUp(): void
    {
        $lead = $this->makeLead();
        $lead->set('intellicheckResponse', $this->successfulIntellicheckResponse());

        $result = $this->makeAction($lead)->execute();

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['intellicheckResponse']);
        $this->assertNotNull($result['idVerificationReport']);

        $fresh = $lead->fresh();
        $this->assertNull($fresh->get('intellicheckResponse'), 'cleanupTemporaryData should have removed it');
        $this->assertIsArray($fresh->get('id_verification'), 'id_verification should still be set');
    }

    public function testIntellicheckResponseFromCustomFieldPopulatesIdVerification(): void
    {
        $lead = $this->makeLead();
        $lead->set('intellicheckResponse', $this->blurryBackImageIntellicheckResponse());

        $this->makeAction($lead)->execute();

        $idVerification = $lead->fresh()->get('id_verification');

        $this->assertIsArray($idVerification);
        $this->assertArrayHasKey('status', $idVerification);
        $this->assertArrayHasKey('intelicheck', $idVerification);
        $this->assertArrayHasKey('scandit', $idVerification);
        $this->assertArrayHasKey('message', $idVerification);
        $this->assertArrayHasKey('intellicheckResponse', $idVerification);
    }

    public function testIntellicheckResponseFallsBackToLeadAttemptLegacyFlatShape(): void
    {
        $lead = $this->makeLead();
        $payload = $this->blurryBackImageIntellicheckResponse();

        LeadAttempt::create([
            'leads_id' => $lead->getId(),
            'companies_id' => $lead->companies_id,
            'apps_id' => $lead->apps_id,
            'header' => [],
            'request' => ['intellicheckResponse' => $payload],
            'ip' => '127.0.0.1',
            'source' => 'test',
            'public_key' => 'test-key',
            'processed' => 0,
        ]);

        $this->makeAction($lead)->execute();

        $idVerification = $lead->fresh()->get('id_verification');

        $this->assertIsArray($idVerification);
        $this->assertArrayHasKey('status', $idVerification);
        $this->assertArrayHasKey('intellicheckResponse', $idVerification);
    }

    public function testIntellicheckResponseFallsBackToLeadAttemptGraphqlInputShape(): void
    {
        $lead = $this->makeLead();
        $payload = $this->blurryBackImageIntellicheckResponse();

        LeadAttempt::create([
            'leads_id' => $lead->getId(),
            'companies_id' => $lead->companies_id,
            'apps_id' => $lead->apps_id,
            'header' => [],
            'request' => [
                'input' => [
                    'branch_id' => '1',
                    'title' => 'Test Sample User',
                    'custom_fields' => [
                        [
                            'name' => 'id_verification',
                            'value' => [
                                'scandit' => true,
                                'intelicheck' => false,
                                'intellicheck_workflow_response' => 'passed',
                            ],
                        ],
                        ['name' => 'intellicheckResponse', 'value' => $payload],
                        [
                            'name' => 'get_docs_drivers_license',
                            'value' => [
                                'address' => '123 TEST ST, SAMPLE CITY, IN, 46327',
                                'license' => '0000-00-0000',
                                'state' => 'IN',
                                'birthday' => ['day' => 1, 'month' => 1, 'year' => 1990],
                                'exp_date' => ['day' => 1, 'month' => 1, 'year' => 2030],
                                'firstname' => 'TEST',
                                'middlename' => 'SAMPLE',
                                'lastname' => 'USER',
                            ],
                        ],
                    ],
                ],
            ],
            'ip' => '127.0.0.1',
            'source' => 'test',
            'public_key' => 'test-key',
            'processed' => 0,
        ]);

        $this->makeAction($lead)->execute();

        $idVerification = $lead->fresh()->get('id_verification');

        $this->assertIsArray($idVerification);
        $this->assertArrayHasKey('status', $idVerification);
        $this->assertArrayHasKey('intellicheckResponse', $idVerification);
    }

    public function testFailedIdcheckMarksIntelicheckFalse(): void
    {
        $lead = $this->makeLead();
        $lead->set('intellicheckResponse', $this->blurryBackImageIntellicheckResponse());

        $this->makeAction($lead)->execute();

        $idVerification = $lead->fresh()->get('id_verification');

        $this->assertSame(
            $idVerification['intelicheck'],
            in_array($idVerification['status'], ['green', 'flag'], true)
        );
    }

    public function testSuccessfulIntellicheckResponseFromCustomFieldPopulatesIdVerification(): void
    {
        $lead = $this->makeLead();
        $lead->set('intellicheckResponse', $this->successfulIntellicheckResponse());

        $this->makeAction($lead)->execute();

        $idVerification = $lead->fresh()->get('id_verification');

        $this->assertIsArray($idVerification);
        $this->assertArrayHasKey('status', $idVerification);
        $this->assertArrayHasKey('intelicheck', $idVerification);
        $this->assertArrayHasKey('scandit', $idVerification);
    }

    public function testSuccessfulIntellicheckResponseFallsBackToLeadAttemptGraphqlInputShape(): void
    {
        $lead = $this->makeLead();
        $payload = $this->successfulIntellicheckResponse();

        LeadAttempt::create([
            'leads_id' => $lead->getId(),
            'companies_id' => $lead->companies_id,
            'apps_id' => $lead->apps_id,
            'header' => [],
            'request' => [
                'input' => [
                    'custom_fields' => [
                        ['name' => 'intellicheckResponse', 'value' => $payload],
                    ],
                ],
            ],
            'ip' => '127.0.0.1',
            'source' => 'test',
            'public_key' => 'test-key',
            'processed' => 0,
        ]);

        $this->makeAction($lead)->execute();

        $idVerification = $lead->fresh()->get('id_verification');

        $this->assertIsArray($idVerification);
        $this->assertArrayHasKey('status', $idVerification);
        $this->assertArrayHasKey('intellicheckResponse', $idVerification);
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // sendVerificationNotification reads company_manager as an array of user ids;
        // every test that sets intellicheckResponse triggers it now that the early
        // return runs notification/PDF first.
        $lead->company->set('company_manager', []);

        return $lead;
    }

    private function makeAction(Lead $lead): ProcessLeadDriverLicenseVerificationAction
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new ProcessLeadDriverLicenseVerificationAction($lead, $app, $company, $user);
    }

    /**
     * Production-shape Intellicheck response, anonymized.
     * Captures the fully successful case: idcheck.success=true with full data,
     * ocr_match.success=true with all fields matched.
     */
    private function successfulIntellicheckResponse(): array
    {
        return [
            'idcheck' => [
                'data' => [
                    'hairColor' => 'Brown',
                    'mediaType' => '2D',
                    'duplicateDate' => '',
                    'issuingJurisdictionCvt' => 'Indiana',
                    'lastName' => 'Sample',
                    'heightFeetInches' => "5' 3\"",
                    'organDonor' => 'Yes',
                    'transactionIdentifier' => '00000000-0000-0000-0000-000000000000',
                    'weightPounds' => '120',
                    'gender' => 'Female',
                    'expirationDate' => '01/01/2030',
                    'state' => 'IN',
                    'docCategory' => 'DL',
                    'firstName' => 'Test',
                    'stateIssuerMismatch' => 'No',
                    'age' => 35,
                    'address1' => '123 Test St',
                    'restrictions' => '',
                    'extendedResultCode' => 'Y',
                    'isRealID' => 'Yes',
                    'expired' => 'No',
                    'postalCode' => '46307',
                    'weightKilograms' => '55',
                    'eyeColor' => 'Green',
                    'driverClass' => '',
                    'dateOfBirth' => '01/01/1990',
                    'testCard' => false,
                    'endorsements' => '',
                    'middleName' => 'Sample',
                    'issuingJurisdictionAbbrv' => 'IN',
                    'isDuplicate' => '',
                    'docType' => 'Operator License',
                    'processResult' => 'DocumentProcessOK',
                    'dLIDNumberRaw' => '0000000000',
                    'socialSecurity' => '',
                    'dLIDNumberFormatted' => '0000-00-0000',
                    'heightCentimeters' => '160',
                    'issueDate' => '01/01/2020',
                    'uniqueID' => 1,
                    'city' => 'Test City',
                    'address2' => '',
                ],
                'success' => true,
                'result' => true,
                'message' => '',
            ],
            'ocr_match' => [
                'data' => [
                    'isRealIdMatch' => true,
                    'isNationalityMatch' => null,
                    'isHeightMatch' => true,
                    'isIssueDateMatch' => true,
                    'isNameMatch' => true,
                    'isCountryCodeMatch' => null,
                    'isDobMatch' => true,
                    'isIssuerNameMatch' => true,
                    'isExpirationDateMatch' => true,
                    'isWeightMatch' => true,
                    'isEyeColorMatch' => true,
                    'isDocumentNumberMatch' => true,
                    'isDlRestrictionsMatch' => true,
                    'isAddressMatch' => true,
                    'isSexMatch' => true,
                    'isDlEndorsementMatch' => true,
                    'isDlClassMatch' => null,
                ],
                'success' => true,
                'message' => '',
                'result' => true,
            ],
            'OCR' => [
                'data' => [
                    'documentNumber' => '0000-00-0000',
                    'faceImageBase64' => null,
                    'isRealID' => 'Yes',
                    'dlEndorsement' => 'NONE',
                    'dateOfIssue' => '2020-01-01',
                    'lastName' => 'Sample',
                    'fullName' => 'Test Sample User',
                    'countryCode' => 'USA',
                    'placeOfBirth' => '',
                    'documentRecognized' => 1,
                    'dateOfBirthFormatted' => '01/01/1990',
                    'dateOfIssueFormatted' => '01/01/2020',
                    'dateOfBirth' => '1990-01-01',
                    'firstName' => 'Test Sample',
                    'dlRestrictions' => 'NONE',
                    'nationality' => '',
                    'eyeColor' => 'Green',
                    'dateOfExpiry' => '2030-01-01',
                    'height' => '160 cm',
                    'weightKilograms' => '54 kg',
                    'dateOfExpiryFormatted' => '01/01/2030',
                    'sex' => 'F',
                    'fullDocumentImageBase64' => null,
                    'dlClass' => 'NONE',
                    'errorMessage' => null,
                    'issuerName' => 'Indiana',
                    'address' => '123 Test St, Test City, IN 46307',
                    'age' => '35',
                ],
                'success' => true,
                'imageQualityFindings' => [],
                'message' => '',
                'result' => true,
            ],
        ];
    }

    /**
     * Production-shape Intellicheck response, anonymized.
     * Captures the "blurry back image" failure case where idcheck fails
     * but OCR succeeds - the most common partial-failure shape we see.
     */
    private function blurryBackImageIntellicheckResponse(): array
    {
        return [
            'idcheck' => [
                'imageQualityFindings' => [
                    [
                        'code' => 401,
                        'message' => 'Image submission error: Blurry document image submitted via /submit-back',
                    ],
                ],
                'data' => [
                    'processResult' => 'DocumentBadDevice',
                ],
                'success' => false,
                'message' => null,
                'result' => false,
            ],
            'ocr_match' => [
                'data' => [
                    'isDobMatch' => null,
                    'isNameMatch' => null,
                    'isSexMatch' => null,
                    'isWeightMatch' => null,
                    'isExpirationDateMatch' => null,
                    'isHeightMatch' => null,
                    'isRealIdMatch' => null,
                    'isDocumentNumberMatch' => null,
                    'isIssuerNameMatch' => null,
                    'isDlEndorsementMatch' => null,
                    'isAddressMatch' => null,
                    'isIssueDateMatch' => null,
                    'isDlRestrictionsMatch' => null,
                    'isCountryCodeMatch' => null,
                    'isNationalityMatch' => null,
                    'isDlClassMatch' => null,
                    'isEyeColorMatch' => null,
                ],
                'message' => null,
                'result' => false,
                'success' => true,
            ],
            'OCR' => [
                'data' => [
                    'dateOfIssue' => '2020-01-01',
                    'issuerName' => 'Indiana',
                    'fullDocumentImageBase64' => null,
                    'lastName' => null,
                    'isRealID' => 'Yes',
                    'weightKilograms' => '70 kg',
                    'placeOfBirth' => null,
                    'dateOfBirthFormatted' => '01/01/1990',
                    'firstName' => 'TEST SAMPLE USER',
                    'dateOfExpiry' => '2030-01-01',
                    'fullName' => 'TEST SAMPLE USER',
                    'eyeColor' => 'Blue',
                    'dateOfIssueFormatted' => '01/01/2020',
                    'nationality' => null,
                    'dateOfBirth' => '1990-01-01',
                    'dlClass' => 'D',
                    'countryCode' => 'USA',
                    'documentRecognized' => 1,
                    'documentNumber' => '0000-00-0000',
                    'dateOfExpiryFormatted' => '01/01/2030',
                    'height' => '170 cm',
                    'faceImageBase64' => null,
                    'sex' => 'M',
                    'dlEndorsement' => 'NONE',
                    'address' => '123 TEST ST, SAMPLE CITY, IN 46327',
                    'errorMessage' => null,
                    'age' => '34',
                    'dlRestrictions' => 'NONE',
                ],
                'success' => true,
                'imageQualityFindings' => [],
                'result' => true,
                'message' => null,
            ],
        ];
    }
}
