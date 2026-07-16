<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\PullLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Webhooks\ProcessReynoldsWebhookJob;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\Connectors\Traits\HasReynoldsConfiguration;
use Tests\TestCase;

final class InboundWebhookTest extends TestCase
{
    use HasReynoldsConfiguration;

    private const FIXTURE_DEALER = 'FIXTUREDEALER001';
    private const FIXTURE_STORE = '02';
    private const FIXTURE_AREA = '01';
    private const FIXTURE_PROSPECT_ID = '2078900';

    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessReynoldsWebhookJob::class],
            ['name' => 'ProcessReynoldsWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testWebhookJobUpsertsLeadFromRealOslEnvelope(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->setupReynoldsConfiguration($app, $company);
        $this->bindDealerLocationKey($company, self::FIXTURE_DEALER, self::FIXTURE_STORE, self::FIXTURE_AREA);

        $envelope = file_get_contents(__DIR__ . '/Fixtures/inbound_osl_envelope.xml');
        $this->assertNotFalse($envelope);

        $result = $this->dispatchWebhookJob($envelope);

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

        // Configure with a composite location key that does NOT match the fixture.
        $this->setupReynoldsConfiguration($app, $company);
        $this->bindDealerLocationKey($company, 'SOMETHING_ELSE', '99', '99');

        $envelope = file_get_contents(__DIR__ . '/Fixtures/inbound_osl_envelope.xml');
        $result = $this->dispatchWebhookJob($envelope);

        $this->assertSame('ignored', $result['status'] ?? null);
        $this->assertStringContainsString('No matching company', (string) ($result['reason'] ?? ''));
    }

    public function testDispositionAssignedSetsLeadOwnerByProspectId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // companies_settings persists across runs (no DatabaseTransactions), so
        // key the dealer on the company id to resolve only to this test's company.
        $dealer = 'DSPDEALER' . $company->getId();
        $prospectId = '30' . $company->getId();

        $this->setupReynoldsConfiguration($app, $company);
        $this->bindDealerLocationKey($company, $dealer, self::FIXTURE_STORE, self::FIXTURE_AREA);

        // Create the lead directly so the assertion doesn't depend on webhook
        // company resolution for the initial upsert.
        $lead = new PullLeadAction($app, $company, $user)->execute([
            'Prospect' => [
                'ProspectId' => $prospectId,
                'ProspectType' => 'Internet',
                'ProspectStatusType' => 'Open',
            ],
            'IndividualCustomer' => ['FirstName' => 'Disp', 'LastName' => 'Tester'],
            'Email' => ['MailTo' => 'disp.tester.' . $company->getId() . '@example.invalid'],
        ]);

        // R&R ships the assigned salesperson as "FirstName LastName". Make the
        // authenticated (company-associated) user match so the scoped lookup hits.
        $user->firstname = 'Tabitha';
        $user->lastname = 'Sumner';
        $user->saveOrFail();

        $envelope = $this->buildDispositionEnvelope(
            $dealer,
            self::FIXTURE_STORE,
            self::FIXTURE_AREA,
            $prospectId,
            'Assigned',
            'Tabitha Sumner',
        );

        $result = $this->dispatchWebhookJob($envelope);

        $this->assertSame('success', $result['status'] ?? null, 'Assigned disposition should update the lead owner.');
        $this->assertSame('DSP', $result['task'] ?? null);
        $this->assertSame('Assigned', $result['event'] ?? null);
        $this->assertSame($user->getId(), $result['owner_id'] ?? null);

        $lead->refresh();
        $this->assertSame($user->getId(), $lead->leads_owner_id, 'Lead owner must be the resolved salesperson.');
    }

    private function buildDispositionEnvelope(
        string $dealer,
        string $store,
        string $area,
        string $prospectId,
        string $event,
        string $salesperson
    ): string {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><soapenv:Header><payloadManifest xmlns="http://www.starstandards.org/webservices/2005/10/transport"><manifest contentID="Content0" namespaceURI="http://www.starstandards.org/STAR" element="rey_SalesAssistCRMPublishLeadDisposition" /></payloadManifest></soapenv:Header><soapenv:Body><PutMessage xmlns="http://www.starstandards.org/webservices/2005/10/transport"><payload><content id="Content0"><rey_SalesAssistCRMPublishLeadDisposition xmlns="http://www.starstandards.org/STAR"><ApplicationArea><BODId>990c453c-15a6-49fa-9185-d5c3b56716f0</BODId><CreationDateTime>2026-07-08T10:37:42</CreationDateTime><Sender><Component>RRCRM</Component><Task>DSP</Task><TransType>O</TransType><DealerNumber>{$dealer}</DealerNumber><StoreNumber>{$store}</StoreNumber><AreaNumber>{$area}</AreaNumber><BusinessUnitName>Fixture Dealership</BusinessUnitName></Sender><Destination><DestinationNameCode>RCI</DestinationNameCode></Destination></ApplicationArea><Record><Identifier><ProspectId>{$prospectId}</ProspectId><NameRecId>54783</NameRecId></Identifier><RCIDispositionEventId>29</RCIDispositionEventId><RCIDispositionEventName>{$event}</RCIDispositionEventName><IsAiGenerated>N</IsAiGenerated><RCIDispositionCompletedByUserId>{$salesperson}</RCIDispositionCompletedByUserId><RCIDispositionCompletedOn>2026-07-08T10:37:42</RCIDispositionCompletedOn><RCIDispositionPrimarySalesperson>{$salesperson}</RCIDispositionPrimarySalesperson></Record></rey_SalesAssistCRMPublishLeadDisposition></content></payload></PutMessage></soapenv:Body></soapenv:Envelope>
XML;
    }

    public function testPullLeadStoresTradeInInVinSolutionShape(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $record = [
            'Prospect' => [
                'ProspectId' => '9911001',
                'ProspectType' => 'Internet',
                'ProspectStatusType' => 'Open',
            ],
            'IndividualCustomer' => ['FirstName' => 'Trade', 'LastName' => 'Tester'],
            'Email' => ['MailTo' => 'trade.tester@example.invalid'],
            'PotentialTrade' => [
                'TradeVehicleVin' => '1FTFW1E5XKFA00000',
                'TradeVehicleYear' => '2019',
                'TradeVehicleMake' => 'Ford',
                'TradeVehicleModel' => 'F-150',
                'TradeVehicleOdometer' => '45,000',
            ],
        ];

        $lead = new PullLeadAction($app, $company, $user)->execute($record);

        $tradeIn = $lead->get(CustomFieldEnum::TRADE_IN->value);

        $this->assertSame('vehicle_trade_id', CustomFieldEnum::TRADE_IN->value);
        $this->assertIsArray($tradeIn);
        $this->assertSame('1FTFW1E5XKFA00000', $tradeIn['vin']);
        $this->assertSame('2019', $tradeIn['year']);
        $this->assertSame('Ford', $tradeIn['make']);
        $this->assertSame('F-150', $tradeIn['model']);
        $this->assertSame(45000, $tradeIn['mileage']);
        $this->assertSame('UNKNOWN', $tradeIn['condition']);
        $this->assertArrayHasKey('payOff', $tradeIn);
    }

    private function bindDealerLocationKey($company, string $dealer, string $store, string $area): void
    {
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, $dealer);
        $company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, $store);
        $company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, $area);
        $company->set(
            ConfigurationEnum::REYNOLDS_DEALER_LOCATION_KEY->value,
            ConfigurationEnum::buildDealerLocationKey($dealer, $store, $area),
        );
    }

    private function dispatchWebhookJob(string $rawXml): array
    {
        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            [],
            [],
            [],
            ['HTTP_CONTENT_TYPE' => 'text/xml'],
            $rawXml,
        );

        $webhookRequest = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        Queue::fake();

        $job = new ProcessReynoldsWebhookJob($webhookRequest);

        return $job->handle();
    }
}
