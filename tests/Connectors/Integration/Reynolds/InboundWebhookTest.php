<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Services\TenantResolver;
use Kanvas\Connectors\Reynolds\Webhooks\ProcessReynoldsWebhookJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Traits\HasReynoldsConfiguration;
use Tests\TestCase;

final class InboundWebhookTest extends TestCase
{
    use HasReynoldsConfiguration;

    private const FIXTURE_DEALER = 'FIXTUREDEALER001';
    private const FIXTURE_STORE = '02';
    private const FIXTURE_AREA = '01';
    private const FIXTURE_PROSPECT_ID = '2078900';

    public function testTenantResolverMatchesCompanyWhenAllThreeIdentifiersAlign(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->setupReynoldsConfiguration($app, $company);
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, self::FIXTURE_DEALER);
        $company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, self::FIXTURE_STORE);
        $company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, self::FIXTURE_AREA);

        $resolved = TenantResolver::fromSender(
            dealerNumber: self::FIXTURE_DEALER,
            storeNumber: self::FIXTURE_STORE,
            areaNumber: self::FIXTURE_AREA,
            app: $app,
        );

        $this->assertNotNull($resolved, 'TenantResolver must find the company when all three identifiers match.');
        $this->assertSame($company->getId(), $resolved->getId());
    }

    public function testTenantResolverReturnsNullWhenStoreOrAreaDoesNotMatch(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->setupReynoldsConfiguration($app, $company);
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, self::FIXTURE_DEALER);
        $company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, self::FIXTURE_STORE);
        $company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, self::FIXTURE_AREA);

        $resolved = TenantResolver::fromSender(
            dealerNumber: self::FIXTURE_DEALER,
            storeNumber: '99',
            areaNumber: self::FIXTURE_AREA,
            app: $app,
        );

        $this->assertNull(
            $resolved,
            'Wrong StoreNumber must not match — all three identifiers form the tenant key.'
        );
    }

    public function testWebhookJobUpsertsLeadFromRealOslEnvelope(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->setupReynoldsConfiguration($app, $company);
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, self::FIXTURE_DEALER);
        $company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, self::FIXTURE_STORE);
        $company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, self::FIXTURE_AREA);

        $envelope = file_get_contents(__DIR__ . '/Fixtures/inbound_osl_envelope.xml');
        $this->assertNotFalse($envelope);

        $result = new ProcessReynoldsWebhookJob($app, $envelope)->handle();

        $this->assertSame('success', $result['status'] ?? null, 'Job should succeed for a valid OSL envelope matching a known tenant.');
        $this->assertSame('OSL', $result['task'] ?? null);
        $this->assertSame(self::FIXTURE_PROSPECT_ID, (string) ($result['prospect_id'] ?? null));

        $lead = Lead::find($result['lead_id']);
        $this->assertNotNull($lead, 'PullLeadAction must have created a Lead row.');
        $this->assertSame(
            self::FIXTURE_PROSPECT_ID,
            (string) $lead->get(CustomFieldEnum::PROSPECT_ID->value),
            'Persisted REYNOLDS_PROSPECT_ID must match the ProspectId from the envelope.'
        );

        $people = People::find($lead->people_id);
        $this->assertNotNull($people, 'A People row must have been synced from IndividualCustomer.');
        $this->assertSame('Will', $people->firstname);
        $this->assertSame('Graham', $people->lastname);
    }

    public function testWebhookJobIgnoresEnvelopeWhenNoCompanyMatchesSender(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        // Configure with values that DO NOT match the fixture.
        $this->setupReynoldsConfiguration($app, $company);
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, 'SOMETHING_ELSE');
        $company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, '99');
        $company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, '99');

        $envelope = file_get_contents(__DIR__ . '/Fixtures/inbound_osl_envelope.xml');
        $result = new ProcessReynoldsWebhookJob($app, $envelope)->handle();

        $this->assertSame('ignored', $result['status'] ?? null);
        $this->assertStringContainsString('No matching company', (string) ($result['reason'] ?? ''));
    }

    public function testControllerReturnsConfirmSuccessXmlForValidEnvelope(): void
    {
        $envelope = file_get_contents(__DIR__ . '/Fixtures/inbound_osl_envelope.xml');

        $response = $this->call(
            'POST',
            '/v1/webhook/reynolds',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml'],
            $envelope,
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $this->assertStringContainsString('<Status>Success</Status>', $response->getContent());
        $this->assertStringContainsString('<StatusCode>0</StatusCode>', $response->getContent());
    }
}
