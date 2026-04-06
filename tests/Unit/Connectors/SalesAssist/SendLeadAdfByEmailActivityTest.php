<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\SalesAssist;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Actions\BuildLeadAdfXmlAction;
use Kanvas\Connectors\SalesAssist\Activities\SendLeadAdfByEmailActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Mockery;
use PHPUnit\Framework\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowOptions;

class SendLeadAdfByEmailActivityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testBuildLeadAdfXmlContainsExpectedBlocks(): void
    {
        $builder = new class () extends BuildLeadAdfXmlAction {
            public function build(array $data): string
            {
                return $this->buildXml($data);
            }
        };

        $xml = $builder->build([
            'leadId' => 'lead-123',
            'requestDate' => '2026-04-02T13:00:00.000+00:00',
            'source' => 'Workflow Test',
            'firstName' => 'Max',
            'lastName' => 'Payne',
            'email' => 'max@example.com',
            'phone' => '3055551212',
            'comments' => 'Interested in a BMW X5',
            'vendorId' => '10',
            'vendorName' => 'Kanvas Motors',
            'providerName' => 'SalesAssist',
            'service' => 'ADF Delivery',
            'vehicle' => [
                'year' => 2024,
                'make' => 'BMW',
                'model' => 'X5',
                'vin' => 'WBA00000000000001',
                'stock' => 'BMW-001',
            ],
        ]);

        $this->assertStringContainsString('<adf>', $xml);
        $this->assertStringContainsString('Workflow Test', $xml);
        $this->assertStringContainsString('max@example.com', $xml);
        $this->assertStringContainsString('BMW', $xml);
        $this->assertStringContainsString('Kanvas Motors', $xml);
    }

    public function testBuildLeadAdfXmlNormalizesInvalidPhoneValues(): void
    {
        $builder = new class () extends BuildLeadAdfXmlAction {
            public function build(array $data): string
            {
                return $this->buildXml($data);
            }
        };

        $xml = $builder->build([
            'leadId' => 'lead-456',
            'source' => 'Workflow Test',
            'firstName' => 'Max',
            'lastName' => 'Payne',
            'email' => 'max@example.com',
            'phone' => '3055551212',
            'phoneType' => 'invalid-type',
            'phoneTime' => 'invalid-time',
        ]);

        $this->assertStringContainsString('type="mobile"', $xml);
        $this->assertStringContainsString('time="day"', $xml);
    }

    public function testSendLeadAdfByEmailActivitySendsUsingWorkflowParams(): void
    {
        $lead = Mockery::mock(Lead::class);
        $company = Mockery::mock(Companies::class);

        $lead->shouldReceive('getId')->andReturn(88);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn(new \stdClass());
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $app = Mockery::mock(AppInterface::class);
        $storedWorkflow = Mockery::mock(StoredWorkflow::class);
        $storedWorkflow->shouldReceive('workflowOptions')->andReturn(new WorkflowOptions());

        $activity = new class (1, '2026-04-02T00:00:00+00:00', $storedWorkflow) extends SendLeadAdfByEmailActivity {
            public bool $bootstrapped = false;
            public bool $sent = false;
            public bool $usedIntegrationWrapper = false;

            protected function bootstrapAppService(AppInterface $app): void
            {
                $this->bootstrapped = true;
            }

            protected function buildAdfXml(Lead $lead, array $params): string
            {
                return '<adf></adf>';
            }

            public function executeIntegration(
                Model $entity,
                AppInterface $app,
                IntegrationsEnum $integration,
                callable $integrationOperation,
                array $additionalParams = [],
                ?Regions $region = null,
                ?Companies $company = null,
                bool $throwException = false
            ): array {
                $this->usedIntegrationWrapper = true;

                return $integrationOperation($entity, $app, null, $additionalParams);
            }

            protected function sendAdfEmail(
                Lead $lead,
                AppInterface $app,
                string $to,
                string $subject,
                string $xml,
                string $attachmentName,
                bool $sendAsAttachment = false
            ): void {
                if ($to === 'adf@example.com' && $subject === 'ADF Subject' && $xml === '<adf></adf>' && $attachmentName === 'lead-88.xml' && $sendAsAttachment === false) {
                    $this->sent = true;
                }
            }
        };

        $response = $activity->execute($lead, $app, [
            'to' => 'adf@example.com',
            'subject' => 'ADF Subject',
            'source' => 'Workflow Test',
            'provider_name' => 'SalesAssist',
            'service' => 'ADF Delivery',
        ]);

        $this->assertTrue($activity->bootstrapped);
        $this->assertTrue($activity->usedIntegrationWrapper);
        $this->assertTrue($activity->sent);
        $this->assertTrue($response['success']);
        $this->assertSame('adf@example.com', $response['to']);
        $this->assertNull($response['attachment']);
        $this->assertSame('body', $response['delivery_mode']);
    }

    public function testSendLeadAdfByEmailActivityCanSendAsAttachment(): void
    {
        $lead = Mockery::mock(Lead::class);
        $company = Mockery::mock(Companies::class);

        $lead->shouldReceive('getId')->andReturn(89);
        $lead->shouldReceive('getAttribute')->with('people')->andReturn(new \stdClass());
        $lead->shouldReceive('getAttribute')->with('company')->andReturn($company);

        $app = Mockery::mock(AppInterface::class);
        $storedWorkflow = Mockery::mock(StoredWorkflow::class);
        $storedWorkflow->shouldReceive('workflowOptions')->andReturn(new WorkflowOptions());

        $activity = new class (1, '2026-04-02T00:00:00+00:00', $storedWorkflow) extends SendLeadAdfByEmailActivity {
            public bool $sent = false;
            public bool $usedIntegrationWrapper = false;

            protected function bootstrapAppService(AppInterface $app): void
            {
            }

            protected function buildAdfXml(Lead $lead, array $params): string
            {
                return '<adf></adf>';
            }

            public function executeIntegration(
                Model $entity,
                AppInterface $app,
                IntegrationsEnum $integration,
                callable $integrationOperation,
                array $additionalParams = [],
                ?Regions $region = null,
                ?Companies $company = null,
                bool $throwException = false
            ): array {
                $this->usedIntegrationWrapper = true;

                return $integrationOperation($entity, $app, null, $additionalParams);
            }

            protected function sendAdfEmail(
                Lead $lead,
                AppInterface $app,
                string $to,
                string $subject,
                string $xml,
                string $attachmentName,
                bool $sendAsAttachment = false
            ): void {
                if ($attachmentName === 'lead-89.xml' && $sendAsAttachment === true) {
                    $this->sent = true;
                }
            }
        };

        $response = $activity->execute($lead, $app, [
            'to' => 'adf@example.com',
            'send_as_attachment' => true,
        ]);

        $this->assertTrue($activity->usedIntegrationWrapper);
        $this->assertTrue($activity->sent);
        $this->assertSame('lead-89.xml', $response['attachment']);
        $this->assertSame('attachment', $response['delivery_mode']);
    }
}
