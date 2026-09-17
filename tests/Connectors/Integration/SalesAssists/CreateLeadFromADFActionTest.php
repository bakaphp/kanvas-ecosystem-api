<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\CreateLeadFromADFAction;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\NervousSystem\DailyLearning\Services\CycleWindowResolverService;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Tests\TestCase;

class CreateLeadFromADFActionTest extends TestCase
{
    public function testExecute(): void
    {
        [$lead, $webhookCall] = $this->createLeadWebhookCall(fn (string $xml) => $xml);

        new CreateLeadFromADFAction($webhookCall)->execute();

        $this->assertIsArray($lead->get(LeadCustomFieldEnum::ADF_LEAD_XML->value));
    }

    public function testExecuteWithCarfaxProcessingInstructionAndFooter(): void
    {
        [$lead, $webhookCall] = $this->createLeadWebhookCall(
            fn (string $xml) => str_replace('<?ADF version="1.0"?>', "<?adf version=\"1.0\"?>\r\n  ", $xml)
                . "\r\n\r\nIf you would like to unsubscribe and stop receiving these emails click here: "
                . 'https://unsubscribe.example.com/u?token=test-token-3D.'
        );

        new CreateLeadFromADFAction($webhookCall)->execute();

        $this->assertIsArray($lead->get(LeadCustomFieldEnum::ADF_LEAD_XML->value));
    }

    public function testExecuteMatchesRequestDateSentWithLocalOffset(): void
    {
        [$lead, $webhookCall] = $this->createLeadWebhookCall(
            fn (string $xml, Lead $lead) => str_replace(
                $lead->created_at->format('Y-m-d\TH:i:s.vP'),
                $lead->created_at->copy()->setTimezone('America/New_York')->format('Y-m-d\TH:i:s.vP'),
                $xml
            )
        );

        new CreateLeadFromADFAction($webhookCall)->execute();

        $this->assertIsArray($lead->get(LeadCustomFieldEnum::ADF_LEAD_XML->value));
    }

    public function testExecuteReadsRequestDateWithoutOffsetInTenantTimezone(): void
    {
        [$lead, $webhookCall] = $this->createLeadWebhookCall(
            fn (string $xml, Lead $lead) => str_replace(
                $lead->created_at->format('Y-m-d\TH:i:s.vP'),
                $lead->created_at->copy()
                    ->setTimezone(CycleWindowResolverService::resolveTimezone($lead->app, $lead->company))
                    ->format('Y-m-d\TH:i:s.v'),
                $xml
            )
        );

        new CreateLeadFromADFAction($webhookCall)->execute();

        $this->assertIsArray($lead->get(LeadCustomFieldEnum::ADF_LEAD_XML->value));
    }

    public function testExecuteSkipsNonAdfEmail(): void
    {
        [, $webhookCall] = $this->createLeadWebhookCall(
            fn () => "Verification Code\r\nTo verify your account, enter this code in TikTok:\r\n000000"
        );

        $result = new CreateLeadFromADFAction($webhookCall)->execute();

        $this->assertSame(['error' => 'ADF prospect not found in payload'], $result);
    }

    /**
     * @return array{0: Lead, 1: ReceiverWebhookCall}
     */
    private function createLeadWebhookCall(callable $shapeBody): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $receiverWebhook = ReceiverWebhook::create([
            'name' => 'ADF Webhook',
            'url' => 'https://example.com/webhook',
            'is_active' => true,
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'action_id' => 0,
            'users_id' => $user->getId(),
        ]);

        $xml = str_replace(
            ['example@kanvas.dev', '8093505555', '2025-09-19T17:00:01.045-07:00'],
            [
                $lead->people->getEmails()->first()->value,
                $lead->people->getCellPhones()->first()->value,
                $lead->created_at->format('Y-m-d\TH:i:s.vP'),
            ],
            $this->getXmlAsString()
        );
        $body = $shapeBody($xml, $lead);

        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiverWebhook->id,
            'url' => 'https://example.com/webhook',
            'payload' => [
                'body-plain' => $body,
                'stripped-text' => strip_tags($body),
            ],
        ]);

        return [$lead, $webhookCall];
    }

    protected function getXmlAsString(): string
    {
        $xmlLead = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <?ADF version="1.0"?>
            <adf>
              <prospect>
                <id sequence="1" source="KanvasShop">1324464021</id>
                <type>Contact Us</type>
                <requestdate>2025-09-19T17:00:01.045-07:00</requestdate>
                <vehicle interest="buy" status="used">
                  <year>2021</year>
                  <make>Toyota</make>
                  <model>RAV4</model>
                  <vin>1111111111111</vin>
                  <stock>125057</stock>
                  <trim>XLE 4dr SUV (2.5L 4cyl 8A)</trim>
                  <colorcombination>
                    <interiorcolor>Gray</interiorcolor>
                    <exteriorcolor>Silver Sky Metallic</exteriorcolor>
                    <preference>1</preference>
                  </colorcombination>
                </vehicle>
                <customer>
                  <contact>
                    <name part="first">John</name>
                    <name part="last">Doe</name>
                    <email>example@kanvas.dev</email>
                    <phone type="voice">8093505555</phone>
                    <address>
                      <street line="1"></street>
                      <city>Fontana</city>
                      <regioncode>CA</regioncode>
                      <postalcode>92337</postalcode>
                      <country>USA</country>
                    </address>
                  </contact>
                  <timeframe>
                    <description></description>
                  </timeframe>
                  <comments><![CDATA[
            This is an Edmunds.com customer who is interested in a vehicle on
            your lot that was found using Edmunds.com's Used Car Inventory search.
                
            Customer is requesting pricing for the vehicle below:
            VIN: 1111111111111
            2021 Toyota RAV4
            Dealer Price: $24,688
            
            User found this vehicle while searching for:
            EngineType: gas
            Make: Toyota
            Model: RAV4
            BodyType: SUV
            Transmission: Automatic
            DriveTrain: front wheel drive
            Year: 2021
            FuelType: regular unleaded
            
            ADDITIONAL INFO:
            ******************************************************************
            ******************************************************************
                  ]]></comments>
                </customer>
                <vendor>
                  <id source="Kanvas GMC">1967865</id>
                  <vendorname>Kanvas GMC</vendorname>
                </vendor>
                <provider>
                  <id source="KanvasShop Direct"></id>
                  <name part="full">KanvasShop</name>
                  <service>KanvasShop Direct</service>
                </provider>
              </prospect>
            </adf>
            XML;

        return $xmlLead;
    }
}
