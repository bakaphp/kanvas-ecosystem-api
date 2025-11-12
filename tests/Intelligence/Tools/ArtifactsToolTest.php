<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Tools\ArtifactsTool;
use Kiwilan\XmlReader\XmlReader;
use Tests\TestCase;

class ArtifactsToolTest extends TestCase
{
    public function testExecute()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $xml = XmlReader::make($this->getXmlAsString(), true, true);
        $xmlArray = $xml->toArray();
        $lead->set(LeadCustomFieldEnum::ADF_LEAD_XML->value, $xml->toArray());
        $data = (new ArtifactsTool($lead))->execute();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('comments', $data);
        $this->assertContains(
            $xmlArray['adf']['prospect']['customer']['comments'],
            $data
        );
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
            Edmunds VDP: https://www.edmunds.com/toyota/rav4/2021/vin/2T3W1RFV0MC156380/?forcePricing=true
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
