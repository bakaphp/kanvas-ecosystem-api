<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Lendflow;

use Kanvas\Apps\Models\Apps;
use Tests\Connectors\Traits\HasLendflowConfiguration;
use Tests\TestCase;

final class SubmitApplicationTest extends TestCase
{
    use HasLendflowConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Lendflow integration tests are skipped in CI');
        }
        if ($this->lendflowApiKey() === null) {
            $this->markTestSkipped('TEST_LENDFLOW_API_KEY not set');
        }
        if ($this->lendflowWorkflowTemplateId() === null) {
            $this->markTestSkipped('TEST_LENDFLOW_WORKFLOW_TEMPLATE_ID not set');
        }
    }

    public function testSubmitApplicationToWorkflowEndpoint(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $client = $this->getLendflowClient($app, $company);

        $response = $client->post('/api/v2/workflow/applications', $this->sampleApplicationPayload());

        $this->assertIsArray($response);

        $applicationId = $response['data']['application_id']
            ?? $response['application_id']
            ?? null;

        $this->assertNotEmpty(
            $applicationId,
            'Lendflow did not return an application_id. Response: ' . (string) json_encode($response)
        );

        fwrite(STDERR, sprintf("\n[Lendflow] sandbox application_id=%s\n", (string) $applicationId));
    }

    /**
     * Mirrors the field shape produced by LendflowService::buildApplicationPayload,
     * with valid EIN/SSN/DOB so the Lendflow sandbox accepts the submission.
     */
    private function sampleApplicationPayload(): array
    {
        $phone = '+19495673800';
        $address = [
            'address_line' => '123 Integration Way',
            'city' => 'Austin',
            'state' => 'TX',
            'country' => 'US',
            'zip' => '78701',
            'is_primary' => true,
        ];

        return [
            'workflow_template_id' => $this->lendflowWorkflowTemplateId(),
            'prequal' => [
                'prequal_requested_amount' => '50000',
            ],
            'business' => [
                'business_legal_name' => 'Kanvas Integration Test LLC',
                'business_dba' => '',
                'business_stated_monthly_revenue' => '40000',
                'business_entity_type' => 1,
                'business_start_date' => '2015-01-01',
                'business_ein' => '123456789',
                'business_addresses' => [$address],
                'business_phone_numbers' => [[
                    'phone_number' => $phone,
                    'is_primary' => true,
                    'line_type' => 'mobile',
                ]],
            ],
            'funding' => [
                'funding_estimated_annual_revenue' => '480000',
                'funding_actual_average_monthly_revenue' => '40000',
                'funding_amount_requested' => '50000',
                'funding_use_of_funds' => 1,
            ],
            'personal' => [[
                'personal_first_name' => 'Test',
                'personal_last_name' => 'Applicant',
                'personal_date_of_birth' => '1985-06-15',
                'personal_ssn_itin' => ['ssn' => '123456789'],
                'personal_telephone' => ['telephone' => $phone],
                'personal_phone_numbers' => [[
                    'phone_number' => $phone,
                    'is_primary' => true,
                    'line_type' => 'mobile',
                ]],
                'personal_email_address' => ['email_address' => 'kanvas-integration-test@example.com'],
                'personal_addresses' => [$address],
                'personal_is_primary' => true,
            ]],
        ];
    }
}
