<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket;

use Exception;
use Illuminate\Support\Facades\Http;

class CustomerClient extends BaseClient
{
    public function createCustomer(array $data): array
    {
        $xml = $this->buildCustomerXML($data, 'create');

        $headers = $this->authService->getHeaders($xml);
        $response = Http::withHeaders($headers)
            ->withBody($xml, 'application/xml')
            ->post($this->baseUrl . '/Customer');

        return $this->parseCustomerResponse($response);
    }

    public function updateCustomer(int $entityId, array $data): array
    {
        $data['entityId'] = $entityId;
        $xml = $this->buildCustomerXML($data, 'update');

        $headers = $this->authService->getHeaders($xml);
        $response = Http::withHeaders($headers)
            ->withBody($xml, 'application/xml')
            ->put($this->baseUrl . '/Customer');

        return $this->parseCustomerResponse($response);
    }

    /**
     * Parse Customer API response
     */
    private function parseCustomerResponse($response): array
    {
        if ($response->failed()) {
            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $response->status(),
                'body' => $response->body(),
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
                'entityId' => (string)($xml->EntityId ?? ''),
                'dmsCustomerId' => (string)($xml->DMSCustomerId ?? ''),
                'errorCode' => (string)($xml->ErrorCode ?? ''),
                'errorMessage' => (string)($xml->ErrorMessage ?? ''),
                'rawXml' => $response->body(),
            ];

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Parse Error: ' . $e->getMessage(),
                'body' => $response->body(),
            ];
        }
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

        return ltrim($xml);
    }

    private function buildIndividualXML(array $data): string
    {
        $entityId = $data['entityId'] ?? '';
        $phone = $data['phone'] ?? '';
        $email = $data['email'] ?? '';

        $xml = "\n          <SpecifiedPerson>";

        if (! empty($entityId)) {
            $xml .= "\n            <ID>{$entityId}</ID>";
        }

        $xml .= "\n            <GivenName>{$data['firstName']}</GivenName>";
        $xml .= "\n            <FamilyName>{$data['lastName']}</FamilyName>";

        if (! empty($phone)) {
            $xml .= "\n            <TelephoneCommunication>";
            $xml .= "\n              <CompleteNumber>{$phone}</CompleteNumber>";
            $xml .= "\n              <UseCode>Home</UseCode>";
            $xml .= "\n            </TelephoneCommunication>";
        }

        if (! empty($email)) {
            $xml .= "\n            <URICommunication>";
            $xml .= "\n              <URIID>{$email}</URIID>";
            $xml .= "\n            </URICommunication>";
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

        $xml = "\n          <SpecifiedOrganization>";

        if (! empty($entityId)) {
            $xml .= "\n            <ID>{$entityId}</ID>";
        }

        $xml .= "\n            <CompanyName>{$companyName}</CompanyName>";

        if (! empty($data['contactFirstName']) && ! empty($data['contactLastName'])) {
            $xml .= "\n            <PrimaryContact>";
            $xml .= "\n              <GivenName>{$data['contactFirstName']}</GivenName>";
            $xml .= "\n              <FamilyName>{$data['contactLastName']}</FamilyName>";

            if (! empty($phone)) {
                $xml .= "\n              <TelephoneCommunication>";
                $xml .= "\n                <CompleteNumber>{$phone}</CompleteNumber>";
                $xml .= "\n                <UseCode>Work</UseCode>";
                $xml .= "\n              </TelephoneCommunication>";
            }

            if (! empty($email)) {
                $xml .= "\n              <URICommunication>";
                $xml .= "\n                <URIID>{$email}</URIID>";
                $xml .= "\n              </URICommunication>";
            }

            $xml .= "\n            </PrimaryContact>";
        }

        $xml .= "\n          </SpecifiedOrganization>";

        return $xml;
    }

    public function searchCustomer(array $searchData): array
    {
        $xml = $this->buildSearchXML($searchData);

        $headers = $this->authService->getHeaders($xml);
        $response = Http::withHeaders($headers)
            ->withBody($xml, 'application/xml')
            ->post($this->baseUrl . '/SearchEntity');

        return $this->parseSearchResponse($response);
    }

    /**
     * Parse Search Entity response
     */
    private function parseSearchResponse($response): array
    {
        if ($response->failed()) {
            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $response->status(),
                'body' => $response->body(),
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
                'entityId' => (string)($xml->EntityId ?? ''),
                'dmsCustomerId' => (string)($xml->DMSCustomerId ?? ''),
                'firstName' => (string)($xml->FirstName ?? ''),
                'lastName' => (string)($xml->LastName ?? ''),
                'email' => (string)($xml->Email ?? ''),
                'phone' => (string)($xml->Phone ?? ''),
                'errorCode' => (string)($xml->ErrorCode ?? ''),
                'errorMessage' => (string)($xml->ErrorMessage ?? ''),
                'rawXml' => $response->body(),
            ];

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Parse Error: ' . $e->getMessage(),
                'body' => $response->body(),
            ];
        }
    }

    public function getCustomerById(int $entityId): array
    {
        return $this->searchCustomer(['entityId' => $entityId]);
    }

    public function getCustomerByEmail(string $email): array
    {
        return $this->searchCustomer(['email' => $email]);
    }

    public function getCustomerByPhone(string $phone): array
    {
        return $this->searchCustomer(['phone' => $phone]);
    }

    public function getCustomerByName(?string $firstName, ?string $lastName): array
    {
        $searchData = [];
        if ($firstName) {
            $searchData['firstName'] = $firstName;
        }
        if ($lastName) {
            $searchData['lastName'] = $lastName;
        }

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

        if (! empty($searchData['entityId'])) {
            $xml .= "\n          <PartyID>{$searchData['entityId']}</PartyID>";
        }

        if (! empty($searchData['dmsCustomerId'])) {
            $xml .= "\n          <DealerManagementSystemID>{$searchData['dmsCustomerId']}</DealerManagementSystemID>";
        }

        if (! empty($searchData['firstName']) || ! empty($searchData['lastName']) ||
            ! empty($searchData['email']) || ! empty($searchData['phone'])) {
            $xml .= "\n          <SpecifiedPerson>";

            if (! empty($searchData['firstName'])) {
                $xml .= "\n            <GivenName>{$searchData['firstName']}</GivenName>";
            }

            if (! empty($searchData['lastName'])) {
                $xml .= "\n            <FamilyName>{$searchData['lastName']}</FamilyName>";
            }

            if (! empty($searchData['phone'])) {
                $xml .= "\n            <TelephoneCommunication>";
                $xml .= "\n              <CompleteNumber>{$searchData['phone']}</CompleteNumber>";
                $xml .= "\n            </TelephoneCommunication>";
            }

            if (! empty($searchData['email'])) {
                $xml .= "\n            <URICommunication>";
                $xml .= "\n              <URIID>{$searchData['email']}</URIID>";
                $xml .= "\n              <ChannelCode>Email Address</ChannelCode>";
                $xml .= "\n            </URICommunication>";
            }

            $xml .= "\n          </SpecifiedPerson>";
        }

        if (! empty($searchData['vin'])) {
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
