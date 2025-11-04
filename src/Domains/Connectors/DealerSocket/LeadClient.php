<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket;

use Exception;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\DealerSocket\BaseClient;

class LeadClient extends BaseClient
{
    public function createSalesLead(array $data)
    {
        $xml = $this->buildSalesLeadXML($data);
        return $this->postLead($xml);
    }

    public function createSalesLeadADF(array $data)
    {
        $xml = $this->buildADFLeadXML($data);
        return $this->postLead($xml);
    }

    public function createServiceLead(array $data)
    {
        $xml = $this->buildServiceLeadXML($data);
        return $this->postLead($xml);
    }

    public function createPartsLead(array $data)
    {
        $xml = $this->buildPartsLeadXML($data);
        return $this->postLead($xml);
    }

    private function postLead(string $xml, string $format = 'star')
    {
        $url = $this->getDirectPostUrl($format);

        $headers = $this->authService->getDirectPostHeaders();

        $response = Http::withHeaders($headers)
            ->withBody($xml, 'application/xml')
            ->post($url);

        return $this->parseLeadResponse($response);
    }

    private function parseLeadResponse($response)
    {
        if ($response->failed()) {
            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $response->status(),
                'body' => $response->body()
            ];
        }

        try {
            $xml = simplexml_load_string($response->body());
            
            if ($xml === false) {
                throw new Exception('Failed to parse XML response');
            }

            $success = strtolower((string)$xml->Success) === 'true';

            $result = [
                'success' => $success,
                'leadId' => (string)($xml->DSLeadId ?? ''),
                'customerId' => (string)($xml->DSCustomerId ?? ''),
                'dealerId' => (string)($xml->DSDealerId ?? ''),
                'existingLeadId' => (string)($xml->DSExistingLeadId ?? ''),
                'assignedId' => (string)($xml->DSAssignedId ?? ''),
                'assignedName' => (string)($xml->DSAssignedName ?? ''),
                'errorCode' => (string)($xml->ErrorCode ?? ''),
                'errorMessage' => (string)($xml->ErrorMessage ?? ''),
                'rawXml' => $response->body()
            ];

            return $result;

        } catch (Exception $e) {

            return [
                'success' => false,
                'error' => 'Parse Error: ' . $e->getMessage(),
                'body' => $response->body()
            ];
        }
    }

    private function buildSalesLeadXML(array $data): string
    {
        $vendorName = $this->authService->getVendorName();
        $dealerId = $this->authService->getDealerId();
        $now = now()->toIso8601String();
        $bodId = $data['bodId'] ?? uniqid('lead_', true);
        
        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<ProcessSalesLead xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
                  xmlns:xsd="http://www.w3.org/2001/XMLSchema" 
                  xmlns="http://www.starstandard.org/STAR/5"
                  xsi:schemaLocation="http://www.starstandard.org/STAR/5"
                  releaseID="5.5.4">
  <ApplicationArea>
    <Sender>
      <CreatorNameCode>{$vendorName}</CreatorNameCode>
      <SenderNameCode>{$data['senderNameCode']}</SenderNameCode>
      <ServiceID>{$data['serviceId']}</ServiceID>
    </Sender>
    <CreationDateTime>{$now}</CreationDateTime>
    <BODID>{$bodId}</BODID>
    <Destination>
      <DealerNumberID>{$dealerId}</DealerNumberID>
    </Destination>
  </ApplicationArea>
  <ProcessSalesLeadDataArea>
    <Process acknowledgeCode="Always" />
    <SalesLead>
      <SalesLeadHeader>
        <DocumentIdentificationGroup>
          <DocumentIdentification>
            <DocumentID>{$data['documentId']}</DocumentID>
          </DocumentIdentification>
        </DocumentIdentificationGroup>
        <LeadInterestCode>{$data['leadInterestCode']}</LeadInterestCode>
        <CustomerComments><![CDATA[{$data['customerComments']}]]></CustomerComments>
        <LeadComments><![CDATA[{$data['leadComments']}]]></LeadComments>
        <CustomerProspect>
          <ProspectParty>
            <RelationshipTypeCode>{$data['customerType']}</RelationshipTypeCode>
            <SpecifiedPerson>
              <GivenName>{$data['firstName']}</GivenName>
              <FamilyName>{$data['lastName']}</FamilyName>
XML;

        if (!empty($data['address'])) {
            $xml .= "\n              <ResidenceAddress>";
            $xml .= "\n                <AddressType>home</AddressType>";
            $xml .= "\n                <LineOne>{$data['address']['street']}</LineOne>";
            $xml .= "\n                <CityName>{$data['address']['city']}</CityName>";
            $xml .= "\n                <CountryID>US</CountryID>";
            $xml .= "\n                <Postcode>{$data['address']['zipCode']}</Postcode>";
            $xml .= "\n                <StateOrProvinceCountrySub-DivisionID>{$data['address']['state']}</StateOrProvinceCountrySub-DivisionID>";
            $xml .= "\n              </ResidenceAddress>";
        }

        if (!empty($data['phone'])) {
            $xml .= "\n              <TelephoneCommunication>";
            $xml .= "\n                <ChannelCode>{$data['phoneType']}</ChannelCode>";
            $xml .= "\n                <LocalNumber>{$data['phone']}</LocalNumber>";
            $xml .= "\n              </TelephoneCommunication>";
        }

        if (!empty($data['email'])) {
            $xml .= "\n              <URICommunication>";
            $xml .= "\n                <URIID>{$data['email']}</URIID>";
            $xml .= "\n                <ChannelCode>email</ChannelCode>";
            $xml .= "\n              </URICommunication>";
        }

        if (!empty($data['contactMethod'])) {
            $xml .= "\n              <ContactMethodTypeCode>{$data['contactMethod']}</ContactMethodTypeCode>";
        }

        $xml .= "\n            </SpecifiedPerson>";

        if (!empty($data['vehicle'])) {
            $xml .= "\n            <CurrentlyOwnedItem>";
            $xml .= "\n              <OwnedVehicleDetail>";
            $xml .= "\n                <SalesLeadOwnedVehicle>";
            $xml .= "\n                  <Vehicle>";
            $xml .= "\n                    <Model>{$data['vehicle']['model']}</Model>";
            $xml .= "\n                    <ModelYear>{$data['vehicle']['year']}</ModelYear>";
            $xml .= "\n                    <MakeString>{$data['vehicle']['make']}</MakeString>";
            if (!empty($data['vehicle']['vin'])) {
                $xml .= "\n                    <VehicleID>{$data['vehicle']['vin']}</VehicleID>";
            }
            $xml .= "\n                  </Vehicle>";
            $xml .= "\n                </SalesLeadOwnedVehicle>";
            $xml .= "\n              </OwnedVehicleDetail>";
            $xml .= "\n            </CurrentlyOwnedItem>";
        }

        $xml .= "\n          </ProspectParty>";
        $xml .= "\n          <LeadCreationDateTime>{$now}</LeadCreationDateTime>";
        $xml .= "\n        </CustomerProspect>";
        $xml .= "\n      </SalesLeadHeader>";
        $xml .= "\n      <SalesLeadDetail>";

        if (!empty($data['financing'])) {
            $xml .= "\n        <Financing>";
            $xml .= "\n          <FinanceTypeString>{$data['financing']['type']}</FinanceTypeString>";
            if (!empty($data['financing']['amount'])) {
                $xml .= "\n          <EstimatedFinancingAmounts>";
                $xml .= "\n            <ApprovedAmount currencyID=\"USD\">{$data['financing']['amount']}</ApprovedAmount>";
                $xml .= "\n          </EstimatedFinancingAmounts>";
            }
            $xml .= "\n        </Financing>";
        }

        if (!empty($data['salesPerson'])) {
            $xml .= "\n        <SalesActivity>";
            $xml .= "\n          <SalesPersonName><![CDATA[{$data['salesPerson']}]]></SalesPersonName>";
            $xml .= "\n        </SalesActivity>";
        }

        if (!empty($data['interestedVehicle'])) {
            $xml .= "\n        <SalesLeadLineItem>";
            $xml .= "\n          <SalesLeadVehicleLineItem>";
            $xml .= "\n            <SalesLeadVehicle>";
            $xml .= "\n              <Model>{$data['interestedVehicle']['model']}</Model>";
            $xml .= "\n              <ModelYear>{$data['interestedVehicle']['year']}</ModelYear>";
            $xml .= "\n              <MakeString>{$data['interestedVehicle']['make']}</MakeString>";
            $xml .= "\n              <SaleClassCode>{$data['interestedVehicle']['status']}</SaleClassCode>";
            $xml .= "\n            </SalesLeadVehicle>";
            $xml .= "\n          </SalesLeadVehicleLineItem>";
            $xml .= "\n        </SalesLeadLineItem>";
        }

        $xml .= "\n      </SalesLeadDetail>";
        $xml .= "\n    </SalesLead>";
        $xml .= "\n  </ProcessSalesLeadDataArea>";
        $xml .= "\n</ProcessSalesLead>";

        return ltrim($xml);
    }

    private function buildADFLeadXML(array $data): string
    {
        $leadId = $data['leadId'] ?? uniqid('lead_', true);
        $requestDate = now()->format('Y-m-d\TH:i:s.u\Z');
        
        $xml = <<<XML
<?xml version="1.0"?>
<adf>
  <prospect>
    <id sequence="1" source="{$data['source']}">{$leadId}</id>
    <requestdate>{$requestDate}</requestdate>
    <vehicle interest="{$data['interest']}" status="{$data['status']}">
      <year><![CDATA[{$data['vehicle']['year']}]]></year>
      <make><![CDATA[{$data['vehicle']['make']}]]></make>
      <model><![CDATA[{$data['vehicle']['model']}]]></model>
XML;

        if (!empty($data['vehicle']['vin'])) {
            $xml .= "\n      <vin><![CDATA[{$data['vehicle']['vin']}]]></vin>";
        }
        if (!empty($data['vehicle']['stock'])) {
            $xml .= "\n      <stock><![CDATA[{$data['vehicle']['stock']}]]></stock>";
        }

        $xml .= "\n    </vehicle>";
        $xml .= "\n    <customer>";
        $xml .= "\n      <contact>";
        $xml .= "\n        <name part=\"{$data['namePart']}\">";
        $xml .= "\n          <![CDATA[{$data['firstName']} {$data['lastName']}]]>";
        $xml .= "\n        </name>";
        $xml .= "\n        <email><![CDATA[{$data['email']}]]></email>";
        $xml .= "\n        <phone type=\"{$data['phoneType']}\" time=\"{$data['phoneTime']}\">";
        $xml .= "\n          <![CDATA[{$data['phone']}]]>";
        $xml .= "\n        </phone>";

        if (!empty($data['address'])) {
            $xml .= "\n        <address>";
            $xml .= "\n          <street line=\"1\"><![CDATA[{$data['address']['street']}]]></street>";
            $xml .= "\n          <city><![CDATA[{$data['address']['city']}]]></city>";
            $xml .= "\n          <regioncode><![CDATA[{$data['address']['state']}]]></regioncode>";
            $xml .= "\n          <postalcode><![CDATA[{$data['address']['zipCode']}]]></postalcode>";
            $xml .= "\n        </address>";
        }

        $xml .= "\n      </contact>";

        if (!empty($data['comments'])) {
            $xml .= "\n      <comments><![CDATA[{$data['comments']}]]></comments>";
        }

        $xml .= "\n    </customer>";
        $xml .= "\n    <vendor>";
        $xml .= "\n      <id source=\"DealerId\"><![CDATA[" . config('dealersocket.dealer_id') . "]]></id>";
        $xml .= "\n      <vendorname>Vendor Name</vendorname>";
        $xml .= "\n      <contact>";
        $xml .= "\n        <name part=\"full\"><![CDATA[{$data['salesPerson']}]]></name>";
        $xml .= "\n      </contact>";
        $xml .= "\n    </vendor>";
        $xml .= "\n    <provider>";
        $xml .= "\n      <name part=\"full\"><![CDATA[{$data['providerName']}]]></name>";
        $xml .= "\n      <service><![CDATA[{$data['service']}]]></service>";
        $xml .= "\n    </provider>";
        $xml .= "\n  </prospect>";
        $xml .= "\n</adf>";

        return ltrim($xml);
    }

    private function buildServiceLeadXML(array $data): string
    {
        $vendorName = $this->authService->getVendorName();
        $dealerId = $this->authService->getDealerId();
        $now = now()->toIso8601String();
        $bodId = $data['bodId'] ?? uniqid('svc_', true);

        $xml = <<<XML
<?xml version="1.0"?>
<ProcessServiceAppointment xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                           xmlns="http://www.starstandard.org/STAR/5"
                           releaseID="5.5.4">
  <ApplicationArea>
    <Sender>
      <CreatorNameCode>{$vendorName}</CreatorNameCode>
      <SenderNameCode>{$data['senderNameCode']}</SenderNameCode>
      <ServiceID>{$data['serviceId']}</ServiceID>
    </Sender>
    <CreationDateTime>{$now}</CreationDateTime>
    <BODID>{$bodId}</BODID>
    <Destination>
      <DealerNumberID>{$dealerId}</DealerNumberID>
    </Destination>
  </ApplicationArea>
  <ProcessServiceAppointmentDataArea>
    <Process acknowledgeCode="Always" />
    <ServiceAppointment>
      <ServiceAppointmentHeader>
        <DocumentDateTime>{$now}</DocumentDateTime>
        <AppointmentContactParty>
          <SpecialRemarksDescription>{$data['specialRemarks']}</SpecialRemarksDescription>
          <SpecifiedPerson>
            <GivenName>{$data['firstName']}</GivenName>
            <FamilyName>{$data['lastName']}</FamilyName>
XML;

        $xml .= <<<XML
          </SpecifiedPerson>
        </AppointmentContactParty>
      </ServiceAppointmentHeader>
      <ServiceAppointmentDetail>
        <ServiceAppointmentVehicleLineItem>
          <Vehicle>
            <Model>{$data['vehicle']['model']}</Model>
            <ModelYear>{$data['vehicle']['year']}</ModelYear>
            <MakeString>{$data['vehicle']['make']}</MakeString>
          </Vehicle>
        </ServiceAppointmentVehicleLineItem>
      </ServiceAppointmentDetail>
    </ServiceAppointment>
  </ProcessServiceAppointmentDataArea>
</ProcessServiceAppointment>
XML;

        return ltrim($xml);
    }

    private function buildPartsLeadXML(array $data): string
    {
        return "";
    }

    private function getDirectPostUrl(string $format): string
    {
        $format = strtolower($format);
        $environment = 'testing';
        $dealerId = $this->authService->getDealerId();

        if ($environment === 'testing' || config('dealersocket.use_oem_testing_url', false)) {
            $baseUrl = 'https://oemwebsecure.dealersocket.com/DSOEMLead/US/DCP';

            if ($format === 'adf') {
                return "{$baseUrl}/ADF/1/SalesLead/223IIV3839";
            } else {
                return "{$baseUrl}/STAR/554/SalesLead/223IIV3839";
            }
        }

        $baseUrl = config('dealersocket.base_url', 'https://api.dealersocket.com/api/DealerSocket');
        return "{$baseUrl}/DirectPost/{$dealerId}";
    }

    /**
     * Search lead by Lead ID
     * 
     * @param string $leadId
     * @return array|null
     */
    public function searchLeadsByEntityId(int $entityId, string $eventCategory = 'Sales'): array
    {
        return $this->searchEvents([
            'entityId' => $entityId,
            'eventCategory' => $eventCategory
        ]);
    }

    public function searchByLeadId(int $eventId, int $entityId): array
    {
        $results = $this->searchEvents([
            'entityId' => $entityId,
            'eventCategory' => 'Sales'
        ]);

        if (!empty($results['events'])) {
            foreach ($results['events'] as $event) {
                if ($event['eventId'] === $eventId) {
                    return $event;
                }
            }
        }
        
        return [];
    }

    public function searchEvents(array $params): array
    {
        $jsonBody = $this->buildEventSearchJSON($params);
        $response = $this->postEventSearch($jsonBody);
        
        return $this->parseEventSearchResponse($response);
    }

    private function buildEventSearchJSON(array $params): string
    {
        $vendorName = $this->authService->getVendorName();
        $dealerId = $this->authService->getDealerId();
        
        $data = [
            'vendor' => $vendorName,
            'dealerId' => $dealerId,
            'entityId' => $params['entityId'],
            'eventCategory' => $params['eventCategory'] ?? 'Sales'
        ];
        
        return json_encode($data);
    }

    protected function postEventSearch(string $jsonBody)
    {
        $headers = $this->authService->getHMACHeaders($jsonBody);
        $headers['Content-Type'] = 'application/json';

        $eventSearchUrl = 'https://iapi.dealersocket.com/webapi/EventSearch';


        $response = Http::withHeaders($headers)
            ->withBody($jsonBody, 'application/json')
            ->post($eventSearchUrl);

        return $this->parseResponse($response);
    }

    /**
     * Format search results for display
     * 
     * @param array $results
     * @return string
     */
    public function formatSearchResults(array $results): string
    {
        if (empty($results)) {
            return "No results found.";
        }

        $output = "Found " . count($results) . " lead(s):\n\n";

        foreach ($results as $index => $lead) {
            $output .= "━━━━━ Lead #" . ($index + 1) . " ━━━━━\n";
            $output .= "Lead ID: " . ($lead['leadId'] ?? 'N/A') . "\n";
            $output .= "Customer ID: " . ($lead['customerId'] ?? 'N/A') . "\n";
            $output .= "Status: " . ($lead['status'] ?? 'N/A') . "\n";
            $output .= "Created: " . ($lead['createdDate'] ?? 'N/A') . "\n";

            if (!empty($lead['customer'])) {
                $output .= "\nCustomer:\n";
                $output .= "  Name: " . ($lead['customer']['firstName'] ?? '') . " " . 
                        ($lead['customer']['lastName'] ?? '') . "\n";
                $output .= "  Email: " . ($lead['customer']['email'] ?? 'N/A') . "\n";
                $output .= "  Phone: " . ($lead['customer']['phone'] ?? 'N/A') . "\n";
            }

            if (!empty($lead['vehicle'])) {
                $output .= "\nVehicle:\n";
                $output .= "  " . ($lead['vehicle']['year'] ?? '') . " " . 
                        ($lead['vehicle']['make'] ?? '') . " " . 
                        ($lead['vehicle']['model'] ?? '') . "\n";
                $output .= "  VIN: " . ($lead['vehicle']['vin'] ?? 'N/A') . "\n";
            }

            $output .= "\n";
        }
        
        return $output;
    }

    /**
     * Parse EventSearch JSON response
     */
    private function parseEventSearchResponse($response): array
    {
        $data = json_decode(json_encode($response), true);

        if (!empty($data['errorCode']) || !empty($data['errorMessage'])) {
            throw new Exception("EventSearch Error: {$data['errorMessage']}");
        }

        return $this->formatEventSearchResults($data);
    }

    private function formatEventSearchResults(array $data): array
    {
        $formatted = [
            'customer' => [
                'firstName' => $data['firstName'] ?? null,
                'lastName' => $data['lastName'] ?? null,
                'middleName' => $data['middleName'] ?? null,
                'suffix' => $data['suffix'] ?? null,
                'companyName' => $data['companyName'] ?? null,
            ],
            'events' => []
        ];

        if (!empty($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $event) {
                $formatted['events'][] = [
                    'eventId' => $event['eventId'] ?? null,
                    'eventCategory' => $event['eventCategory'] ?? null, // 1=Sales, 2=Service
                    'eventType' => $event['eventType'] ?? null,
                    'status' => $event['status'] ?? null,
                    'statusName' => $this->getStatusName($event['status'] ?? null, $event['eventCategory'] ?? null),
                    'insertDate' => $event['insertDate'] ?? null,
                    'updateDate' => $event['updateDate'] ?? null,
                    'personAssigned' => $event['primaryAssigned'] ?? null,
                    'vehicle' => [
                        'year' => $event['year'] ?? null,
                        'make' => $event['make'] ?? null,
                        'model' => $event['model'] ?? null,
                        'vin' => $event['vin'] ?? null,
                        'stockNumber' => $event['stockNumber'] ?? null,
                        'currentMileage' => $event['currentMileage'] ?? null,
                    ]
                ];
            }
        }

        return $formatted;
    }

    private function getStatusName(?int $status, ?int $category): ?string
    {
        if ($status === null || $category === null) {
            return null;
        }

        if ($category === 1 || $category === 4) {
            $salesStatuses = [
                220 => '0 - Unqualified',
                221 => '1 - Up/Contacted',
                227 => '2 - Store Visit',
                222 => '3 - Demo Vehicle',
                223 => '4 - Write Up',
                224 => '5 - Pending F&I',
                225 => '6 - Sold',
                226 => '7 - Lost',
            ];
            return $salesStatuses[$status] ?? "Unknown ($status)";
        }

        if ($category === 2) {
            $serviceStatuses = [
                100165 => '0 - Unqualified',
                100166 => '1 - Appointment',
                100167 => '2 - Diagnosis',
                100168 => '3 - In-Service',
                100169 => '4 - Completed',
                100170 => '5 - Lost',
            ];
            return $serviceStatuses[$status] ?? "Unknown ($status)";
        }

        return "Unknown ($status)";
    }
}
