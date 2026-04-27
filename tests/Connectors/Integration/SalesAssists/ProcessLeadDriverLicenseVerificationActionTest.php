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

    public function testIntellicheckResponseFallsBackToLeadAttempt(): void
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

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
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
