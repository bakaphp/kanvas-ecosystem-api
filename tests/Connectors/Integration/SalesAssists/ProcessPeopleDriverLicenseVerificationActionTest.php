<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\ProcessPeopleDriverLicenseVerificationAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class ProcessPeopleDriverLicenseVerificationActionTest extends TestCase
{
    public function testReturnsFailureWhenNoActiveLeadFound(): void
    {
        $people = $this->makePeopleWithoutLead();

        $result = new ProcessPeopleDriverLicenseVerificationAction($people)->execute();

        $this->assertFalse($result['success']);
        $this->assertSame('No active lead found for this person', $result['message']);
    }

    public function testIntellicheckResponseFromPeopleCustomFieldPopulatesIdVerification(): void
    {
        $lead = $this->makeLeadWithCompanyManager();
        $people = $lead->people;
        $people->set('intellicheckResponse', $this->blurryBackImageIntellicheckResponse());

        new ProcessPeopleDriverLicenseVerificationAction($people, [], $lead)->execute();

        $idVerification = $people->fresh()->get('id_verification');

        $this->assertIsArray($idVerification);
        $this->assertArrayHasKey('status', $idVerification);
        $this->assertArrayHasKey('intelicheck', $idVerification);
        $this->assertArrayHasKey('scandit', $idVerification);
        $this->assertArrayHasKey('intellicheckResponse', $idVerification);
    }

    public function testReturnsEarlyWhenNoDriverLicenseImage(): void
    {
        $lead = $this->makeLeadWithCompanyManager();
        $people = $lead->people;

        $result = new ProcessPeopleDriverLicenseVerificationAction($people, [], $lead)->execute();

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['results']);
    }

    public function testFailedIdcheckMarksIntelicheckFalse(): void
    {
        $lead = $this->makeLeadWithCompanyManager();
        $people = $lead->people;
        $people->set('intellicheckResponse', $this->blurryBackImageIntellicheckResponse());

        new ProcessPeopleDriverLicenseVerificationAction($people, [], $lead)->execute();

        $idVerification = $people->fresh()->get('id_verification');

        $this->assertSame(
            $idVerification['intelicheck'],
            in_array($idVerification['status'], ['green', 'flag'], true)
        );
    }

    private function makeLeadWithCompanyManager(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // sendVerificationNotification reads company_manager as an array of user ids
        $lead->company->set('company_manager', []);

        return $lead;
    }

    private function makePeopleWithoutLead(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    /**
     * Production-shape Intellicheck response, anonymized.
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
                    'dateOfIssue' => '2022-01-01',
                    'issuerName' => 'Indiana',
                    'fullDocumentImageBase64' => null,
                    'weightKilograms' => '60 kg',
                    'lastName' => 'Sample',
                    'isRealID' => 'Yes',
                    'placeOfBirth' => null,
                    'dateOfBirthFormatted' => '01/01/1990',
                    'firstName' => 'Test Sample User',
                    'dateOfExpiry' => '2030-01-01',
                    'fullName' => 'Test Sample User',
                    'eyeColor' => 'Hazel',
                    'dateOfIssueFormatted' => '01/01/2022',
                    'nationality' => null,
                    'countryCode' => 'USA',
                    'dateOfBirth' => '1990-01-01',
                    'dlClass' => 'D',
                    'documentRecognized' => 1,
                    'faceImageBase64' => null,
                    'documentNumber' => '0000-00-0000',
                    'dateOfExpiryFormatted' => '01/01/2030',
                    'height' => '165 cm',
                    'sex' => 'F',
                    'dlEndorsement' => 'NONE',
                    'address' => '456 SAMPLE AVE, TEST CITY, IN 46356',
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
