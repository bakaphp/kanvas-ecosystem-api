<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\CreateLeadFromADFAction;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Tests\TestCase;

class CreateLeadFromADFActionTest extends TestCase
{
    public function testExecute(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $ReceiverWebhook = ReceiverWebhook::create([
            'name' => 'ADF Webhook',
            'url' => 'https://example.com/webhook',
            'is_active' => true,
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'action_id' => 0,
            'users_id' => $user->getId(),
        ]);
        $xml = $this->getXmlAsString();
        $email = $lead->people->getEmails()->first()->value;
        $phone = $lead->people->getCellPhones()->first()->value;
        $xml = str_replace('frederickpeal@mctekk.com', $email, $xml);
        $xml = str_replace('8098843010', $phone, $xml);
        $xml = str_replace('2025-09-19T17:00:01.045-07:00', $lead->created_at->format('Y-m-d\TH:i:s.vP'), $xml);
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $ReceiverWebhook->id,
            'url' => 'https://example.com/webhook',
            'payload' => [
                'body-plain' => $xml,
                'stripped-text' => strip_tags($xml),
            ],
        ]);
        $data = (new CreateLeadFromADFAction($webhookCall))->execute();
        $this->assertIsArray($lead->get(LeadCustomFieldEnum::ADF_LEAD_XML->value));
        $this->assertTrue(true);
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
                    <name part="first">vanessa</name>
                    <name part="last">garcia-moreno</name>
                    <email>frederickpeal@mctekk.com</email>
                    <phone type="voice">8098843010</phone>
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
