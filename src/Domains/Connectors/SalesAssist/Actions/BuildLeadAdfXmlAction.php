<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

class BuildLeadAdfXmlAction
{
    public function execute(Lead $lead, array $overrides = []): string
    {
        $data = array_merge($this->mapLeadToAdfData($lead), $overrides);

        return $this->buildXml($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapLeadToAdfData(Lead $lead): array
    {
        $people = $lead->people;

        return [
            'leadId' => (string) ($lead->uuid ?? $lead->getId()),
            'requestDate' => $lead->created_at?->format('Y-m-d\TH:i:s.vP') ?? now()->format('Y-m-d\TH:i:s.vP'),
            'source' => $this->getLeadSource($lead),
            'providerName' => 'SalesAssist',
            'service' => 'SalesAssist Lead Delivery',
            'vendorId' => (string) $lead->company->getId(),
            'vendorName' => $lead->company->name,
            'salesPerson' => $this->getSalesPerson($lead),
            'firstName' => $people?->firstname ?? $lead->firstname ?? 'Unknown',
            'lastName' => $people?->lastname ?? $lead->lastname ?? 'Lead',
            'email' => $people ? $this->getEmailFromPeople($people) : ($lead->email ?? ''),
            'phone' => $people ? $this->getPhoneFromPeople($people) : ($lead->phone ?? ''),
            'phoneType' => $this->getPhoneTypeFromPeople($people),
            'phoneTime' => 'day',
            'address' => $people ? $this->getAddressFromPeople($people) : null,
            'comments' => $this->getComments($lead),
            'vehicle' => $this->getInterestedVehicle($lead),
            'interest' => 'buy',
            'status' => $this->getVehicleStatus($lead),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function buildXml(array $data): string
    {
        $leadId = $this->escape((string) ($data['leadId'] ?? uniqid('lead_', true)));
        $source = $this->escape((string) ($data['source'] ?? 'Kanvas'));
        $requestDate = $this->escape((string) ($data['requestDate'] ?? now()->format('Y-m-d\TH:i:s.vP')));
        $interest = $this->escape((string) ($data['interest'] ?? 'buy'));
        $status = $this->escape((string) ($data['status'] ?? 'used'));
        $namePart = $this->escape((string) ($data['namePart'] ?? 'full'));
        $phoneType = $this->resolvePhoneType((string) ($data['phoneType'] ?? 'mobile'));
        $phoneTime = $this->resolvePhoneTime((string) ($data['phoneTime'] ?? 'day'));

        $firstName = $this->cdata((string) ($data['firstName'] ?? ''));
        $lastName = $this->cdata((string) ($data['lastName'] ?? ''));
        $email = $this->cdata((string) ($data['email'] ?? ''));
        $phone = $this->cdata((string) ($data['phone'] ?? ''));
        $comments = $data['comments'] ?? null;
        $vehicle = $data['vehicle'] ?? null;
        $address = is_array($data['address'] ?? null) ? $data['address'] : null;
        $vendorId = $this->cdata((string) ($data['vendorId'] ?? ''));
        $vendorName = $this->cdata((string) ($data['vendorName'] ?? ''));
        $salesPerson = $this->cdata((string) ($data['salesPerson'] ?? ''));
        $providerName = $this->cdata((string) ($data['providerName'] ?? 'SalesAssist'));
        $service = $this->cdata((string) ($data['service'] ?? 'SalesAssist Lead Delivery'));

        $xml = <<<XML
<?xml version="1.0"?>
<adf>
  <prospect>
    <id sequence="1" source="{$source}">{$leadId}</id>
    <requestdate>{$requestDate}</requestdate>
XML;

        if (is_array($vehicle) && ! empty(array_filter($vehicle))) {
            $xml .= "\n    <vehicle interest=\"{$interest}\" status=\"{$status}\">";
            $xml .= "\n      <year>" . $this->cdata((string) ($vehicle['year'] ?? '')) . '</year>';
            $xml .= "\n      <make>" . $this->cdata((string) ($vehicle['make'] ?? '')) . '</make>';
            $xml .= "\n      <model>" . $this->cdata((string) ($vehicle['model'] ?? '')) . '</model>';

            if (! empty($vehicle['vin'])) {
                $xml .= "\n      <vin>" . $this->cdata((string) $vehicle['vin']) . '</vin>';
            }

            if (! empty($vehicle['stock'])) {
                $xml .= "\n      <stock>" . $this->cdata((string) $vehicle['stock']) . '</stock>';
            }

            $xml .= "\n    </vehicle>";
        }

        $xml .= "\n    <customer>";
        $xml .= "\n      <contact>";
        $xml .= "\n        <name part=\"{$namePart}\">{$firstName} {$lastName}</name>";
        $xml .= "\n        <email>{$email}</email>";
        $xml .= "\n        <phone type=\"{$phoneType}\" time=\"{$phoneTime}\">{$phone}</phone>";

        if ($address) {
            $xml .= "\n        <address>";
            $xml .= "\n          <street line=\"1\">" . $this->cdata((string) ($address['street'] ?? '')) . '</street>';
            $xml .= "\n          <city>" . $this->cdata((string) ($address['city'] ?? '')) . '</city>';
            $xml .= "\n          <regioncode>" . $this->cdata((string) ($address['state'] ?? '')) . '</regioncode>';
            $xml .= "\n          <postalcode>" . $this->cdata((string) ($address['zipCode'] ?? '')) . '</postalcode>';
            $xml .= "\n        </address>";
        }

        $xml .= "\n      </contact>";

        if ($comments) {
            $xml .= "\n      <comments>" . $this->cdata((string) $comments) . '</comments>';
        }

        $xml .= "\n    </customer>";
        $xml .= "\n    <vendor>";
        $xml .= "\n      <id source=\"DealerId\">{$vendorId}</id>";
        $xml .= "\n      <vendorname>{$vendorName}</vendorname>";
        $xml .= "\n      <contact><name part=\"full\">{$salesPerson}</name></contact>";
        $xml .= "\n    </vendor>";
        $xml .= "\n    <provider>";
        $xml .= "\n      <name part=\"full\">{$providerName}</name>";
        $xml .= "\n      <service>{$service}</service>";
        $xml .= "\n    </provider>";
        $xml .= "\n  </prospect>";
        $xml .= "\n</adf>";

        return ltrim($xml);
    }

    protected function getEmailFromPeople(People $people): ?string
    {
        return $people->getEmails()->first()?->value ?? null;
    }

    protected function getPhoneFromPeople(People $people): string
    {
        try {
            $phones = $people->getAllPhones();

            if ($phones->isEmpty()) {
                return '';
            }

            return (string) preg_replace('/[^0-9]/', '', $phones->first()->value);
        } catch (Throwable) {
            return '';
        }
    }

    protected function getPhoneTypeFromPeople(?People $people): string
    {
        try {
            if (! $people) {
                return 'mobile';
            }

            $phones = $people->getAllPhones();

            if ($phones->isEmpty()) {
                return 'mobile';
            }

            return match (strtolower($phones->first()->type->name ?? 'mobile')) {
                'phone', 'work', 'business phone' => 'voice',
                'fax' => 'fax',
                default => 'mobile',
            };
        } catch (Throwable) {
            return 'mobile';
        }
    }

    /**
     * @return array<string, string>|null
     */
    protected function getAddressFromPeople(People $people): ?array
    {
        try {
            $address = $people->address()->where('is_default', 1)->where('is_deleted', 0)->first()
                ?? $people->address()->where('is_deleted', 0)->first();

            if (! $address) {
                return null;
            }

            $data = [
                'street' => trim(($address->address ?? '') . ' ' . ($address->address_2 ?? '')),
                'city' => $address->city ?? '',
                'state' => $address->state ?? '',
                'zipCode' => $address->zip ?? '',
            ];

            return array_filter($data) === [] ? null : $data;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getLeadSource(Lead $lead): string
    {
        try {
            return $lead->source?->name ?? 'Kanvas';
        } catch (Throwable) {
            return 'Kanvas';
        }
    }

    protected function getSalesPerson(Lead $lead): string
    {
        try {
            return $lead->owner?->displayname ?? '';
        } catch (Throwable) {
            return '';
        }
    }

    protected function getComments(Lead $lead): string
    {
        $comments = [];

        if (! empty($lead->title)) {
            $comments[] = 'Title: ' . $lead->title;
        }

        if (! empty($lead->description)) {
            $comments[] = $lead->description;
        }

        try {
            $customerComments = $lead->get('customer_comments');
            if (! empty($customerComments)) {
                $comments[] = (string) $customerComments;
            }
        } catch (Throwable) {
        }

        return implode("\n\n", $comments);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getInterestedVehicle(Lead $lead): ?array
    {
        try {
            $vehicle = $lead->get('vehicle_of_interest');

            if (! is_array($vehicle)) {
                return null;
            }

            $mapped = [
                'year' => $vehicle['yearFrom'] ?? $vehicle['yearTo'] ?? $vehicle['year'] ?? null,
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'vin' => $vehicle['vin'] ?? null,
                'stock' => $vehicle['stockNumber'] ?? $vehicle['stock'] ?? null,
            ];

            return array_filter($mapped) === [] ? null : $mapped;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getVehicleStatus(Lead $lead): string
    {
        try {
            $vehicle = $lead->get('vehicle_of_interest');

            if (! is_array($vehicle)) {
                return 'used';
            }

            if (isset($vehicle['isNew'])) {
                return $vehicle['isNew'] === true || $vehicle['isNew'] === 'true' ? 'new' : 'used';
            }

            return strtolower((string) ($vehicle['status'] ?? 'used'));
        } catch (Throwable) {
            return 'used';
        }
    }

    protected function resolvePhoneType(string $phoneType): string
    {
        $validTypes = ['voice', 'mobile', 'fax'];

        return in_array(strtolower($phoneType), $validTypes, true) ? strtolower($phoneType) : 'mobile';
    }

    protected function resolvePhoneTime(string $phoneTime): string
    {
        $validTimes = ['day', 'evening', 'night'];
        $phoneTime = strtolower(trim($phoneTime));

        return in_array($phoneTime, $validTimes, true) ? $phoneTime : 'day';
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function cdata(string $value): string
    {
        $safeValue = str_replace(']]>', ']]]]><![CDATA[>', $value);

        return '<![CDATA[' . $safeValue . ']]>';
    }
}
