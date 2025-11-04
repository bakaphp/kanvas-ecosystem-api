<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket;

use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\DealerSocket\Services\AuthService;

class CustomerClient extends BaseClient
{
    protected AuthService $authService;
    protected string $baseUrl;

    public function createCustomer(array $data)
    {
        $xml = $this->buildCustomerXML($data, 'create');
        return $this->post('/Customer', $xml);
    }

    public function updateCustomer(int $entityId, array $data)
    {
        $data['entityId'] = $entityId;
        $xml = $this->buildCustomerXML($data, 'update');
        
        $headers = $this->authService->getHeaders($xml);
        $response = Http::withHeaders($headers)
            ->withBody($xml, 'application/xml')
            ->put($this->baseUrl . '/Customer');
            
        return $this->parseResponse($response);
    }

    private function buildCustomerXML(array $data, string $action): string
    {
        $vendorName = $this->authService->getVendorName();
        $dealerId = $this->authService->getDealerId();
        $now = now()->toIso8601String();
        
        $xml = <<<XML
          <?xml version="1.0" encoding="utf-8"?>
          <ProcessCustomerInformation xmlns="http://www.starstandard.org/STAR/5">
            <ApplicationArea>
              <Sender>
                <CreatorNameCode>{$vendorName}</CreatorNameCode>
                <DealerNumberID>{$dealerId}</DealerNumberID>
              </Sender>
              <CreationDateTime>{$now}</CreationDateTime>
            </ApplicationArea>
            <ProcessCustomerInformationDataArea>
              <CustomerInformation>
                <CustomerInformationDetail>
                  <CustomerParty>
                    <SpecialRemarksDescription>{$data['type']}</SpecialRemarksDescription>
          XML;

                  if ($data['type'] === 'Individual') {
                      $xml .= $this->buildIndividualXML($data);
                  } else {
                      $xml .= $this->buildCompanyXML($data);
                  }

                  $xml .= <<<XML
                  </CustomerParty>
                </CustomerInformationDetail>
              </CustomerInformation>
            </ProcessCustomerInformationDataArea>
          </ProcessCustomerInformation>
          XML;

        return $xml;
    }

    private function buildIndividualXML(array $data): string
    {
        $entityId = $data['entityId'] ?? '';
        $phone = $data['phone'] ?? '';
        $email = $data['email'] ?? '';
        
        $xml = <<<XML
          <SpecifiedPerson>
        XML;

        if (!empty($entityId)) {
            $xml .= "\n            <ID>{$entityId}</ID>";
        }

        $xml .= <<<XML
            <GivenName>{$data['firstName']}</GivenName>
            <FamilyName>{$data['lastName']}</FamilyName>
        XML;

        if (!empty($phone)) {
            $xml .= <<<XML
              <TelephoneCommunication>
                <CompleteNumber>{$phone}</CompleteNumber>
                <UseCode>Home</UseCode>
              </TelephoneCommunication>
            XML;
        }

        if (!empty($email)) {
          $xml .= <<<XML
            <URICommunication>
              <URIID>{$email}</URIID>
            </URICommunication>
          XML;
        }

        $xml .= "\n          </SpecifiedPerson>";

        return $xml;
    }

    private function buildCompanyXML(array $data): string
    {
        $entityId = $data['entityId'] ?? '';
        $companyName = $data['companyName'] ?? '';
        $phone = $data['phone'] ?? '';
        $email = $data['email'] ?? '';
        
        $xml = <<<XML
          <SpecifiedOrganization>
        XML;

        if (!empty($entityId)) {
            $xml .= "\n            <ID>{$entityId}</ID>";
        }
        
        $xml .= <<<XML
            <CompanyName>{$companyName}</CompanyName>
        XML;

        if (!empty($data['contactFirstName']) && !empty($data['contactLastName'])) {
            $xml .= <<<XML
              <PrimaryContact>
                <GivenName>{$data['contactFirstName']}</GivenName>
                <FamilyName>{$data['contactLastName']}</FamilyName>
            XML;

            if (!empty($phone)) {
              $xml .= <<<XML
                <TelephoneCommunication>
                  <CompleteNumber>{$phone}</CompleteNumber>
                  <UseCode>Work</UseCode>
                </TelephoneCommunication>
              XML;
            }

            if (!empty($email)) {
              $xml .= <<<XML
                <URICommunication>
                  <URIID>{$email}</URIID>
                </URICommunication>
              XML;
            }

            $xml .= "\n            </PrimaryContact>";
        }

        $xml .= "\n          </SpecifiedOrganization>";

        return $xml;
    }

    public function searchCustomer(array $searchData)
    {
      $xml = $this->buildSearchXML($searchData);

      return $this->post('/SearchEntity', $xml);
    }

    public function getCustomerById(int $entityId)
    {
      return $this->searchCustomer(['entityId' => $entityId]);
    }

    public function getCustomerByEmail(string $email)
    {
      return $this->searchCustomer(['email' => $email]);
    }

    public function getCustomerByPhone(string $phone)
    {
      return $this->searchCustomer(['phone' => $phone]);
    }

    public function getCustomerByName(?string $firstName, ?string $lastName)
    {
      $searchData = [];
      if ($firstName) $searchData['firstName'] = $firstName;
      if ($lastName) $searchData['lastName'] = $lastName;

      return $this->searchCustomer($searchData);
    }

private function buildSearchXML(array $searchData): string
{
    $vendorName = $this->authService->getVendorName();
    $dealerId = $this->authService->getDealerId();
    $now = now()->toIso8601String();

    $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetCustomerInformation xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.starstandard.org/STAR/5">
  <ApplicationArea>
    <Sender>
      <CreatorNameCode>{$vendorName}</CreatorNameCode>
      <SenderNameCode>{$vendorName}</SenderNameCode>
      <DealerNumberID>{$dealerId}</DealerNumberID>
    </Sender>
    <CreationDateTime>{$now}</CreationDateTime>
    <Destination>
      <DestinationNameCode>DS</DestinationNameCode>
    </Destination>
  </ApplicationArea>
  <GetCustomerInformationDataArea>
    <CustomerInformation>
      <CustomerInformationDetail>
        <CustomerParty>
XML;

    if (!empty($searchData['entityId'])) {
        $xml .= "\n          <PartyID>{$searchData['entityId']}</PartyID>";
    }

    if (!empty($searchData['dmsCustomerId'])) {
        $xml .= "\n          <DealerManagementSystemID>{$searchData['dmsCustomerId']}</DealerManagementSystemID>";
    }

    if (!empty($searchData['firstName']) || !empty($searchData['lastName']) || 
        !empty($searchData['email']) || !empty($searchData['phone'])) {
        
        $xml .= "\n          <SpecifiedPerson>";
        
        if (!empty($searchData['firstName'])) {
            $xml .= "\n            <GivenName>{$searchData['firstName']}</GivenName>";
        }
        
        if (!empty($searchData['lastName'])) {
            $xml .= "\n            <FamilyName>{$searchData['lastName']}</FamilyName>";
        }
        
        if (!empty($searchData['phone'])) {
            $xml .= "\n            <TelephoneCommunication>";
            $xml .= "\n              <CompleteNumber>{$searchData['phone']}</CompleteNumber>";
            $xml .= "\n            </TelephoneCommunication>";
        }
        
        if (!empty($searchData['email'])) {
            $xml .= "\n            <URICommunication>";
            $xml .= "\n              <URIID>{$searchData['email']}</URIID>";
            $xml .= "\n              <ChannelCode>Email Address</ChannelCode>";
            $xml .= "\n            </URICommunication>";
        }
        
        $xml .= "\n          </SpecifiedPerson>";
    }

    if (!empty($searchData['vin'])) {
        $xml .= "\n        </CustomerParty>";
        $xml .= "\n        <Vehicle>";
        $xml .= "\n          <VehicleID>{$searchData['vin']}</VehicleID>";
        $xml .= "\n        </Vehicle>";
        $xml .= "\n        <CustomerParty>";
    }

    $xml .= <<<XML

        </CustomerParty>
      </CustomerInformationDetail>
    </CustomerInformation>
  </GetCustomerInformationDataArea>
</GetCustomerInformation>
XML;

    return ltrim($xml);
  }
}