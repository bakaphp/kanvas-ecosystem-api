<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\SalesAssist;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\SalesAssist\Actions\BuildLeadAdfXmlAction;
use Kanvas\Connectors\SalesAssist\Activities\SendLeadAdfByEmailActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Mockery;
use PHPUnit\Framework\TestCase;

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
        $lead = new Lead();
        $lead->id = 88;
        $lead->setRelation('people', new \stdClass());
        $lead->setRelation('company', new \stdClass());

        $app = Mockery::mock(AppInterface::class);

        $activity = new class () extends SendLeadAdfByEmailActivity {
            public bool $bootstrapped = false;
            public bool $sent = false;

            protected function bootstrapAppService(AppInterface $app): void
            {
                $this->bootstrapped = true;
            }

            protected function buildAdfXml(Lead $lead, array $params): string
            {
                return '<adf></adf>';
            }

            protected function sendAdfEmail(
                Lead $lead,
                AppInterface $app,
                string $to,
                string $subject,
                string $xml,
                string $attachmentName
            ): void {
                if ($to === 'adf@example.com' && $subject === 'ADF Subject' && $xml === '<adf></adf>' && $attachmentName === 'lead-88.xml') {
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
        $this->assertTrue($activity->sent);
        $this->assertTrue($response['success']);
        $this->assertSame('adf@example.com', $response['to']);
        $this->assertSame('lead-88.xml', $response['attachment']);
    }
}
