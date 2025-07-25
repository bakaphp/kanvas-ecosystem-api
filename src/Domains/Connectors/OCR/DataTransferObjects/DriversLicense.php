<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OCR\DataTransferObjects;

use Spatie\LaravelData\Data;

class DriversLicense extends Data
{
    public function __construct(
        public string $region,
        public string $documentNumber,
        public string $vehicleClassification,
        public string $expirationDate,
        public string $lastName,
        public string $firstName,
        public string $fullName,
        public string $streetAddress,
        public string $city,
        public string $state,
        public string $zipCode,
        public string $birthDate,
        public string $restriction,
        public string $endorsements,
        public string $sex,
        public string $hairColor,
        public string $eyeColor,
        public string $height,
        public string $weight,
        public string $issueDate,
        public string $documentDiscriminator
    ) {
        $this->region = $region;
        $this->documentNumber = $documentNumber;
        $this->vehicleClassification = $vehicleClassification;
        $this->expirationDate = $expirationDate;
        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->fullName = $fullName;
        $this->streetAddress = $streetAddress;
        $this->city = $city;
        $this->state = $state;
        $this->zipCode = $zipCode;
        $this->birthDate = $birthDate;
        $this->restriction = $restriction;
        $this->endorsements = $endorsements;
        $this->sex = $sex;
        $this->hairColor = $hairColor;
        $this->eyeColor = $eyeColor;
        $this->height = $height;
        $this->weight = $weight;
        $this->issueDate = $issueDate;
        $this->documentDiscriminator = $documentDiscriminator;
    }

    public static function fromArray(array $license): self
    {
        $fromIntellicheck = isset($license['license']) && isset($license['address']) && isset($license['state']) && isset($license['license']);
        if ($fromIntellicheck) {
            return self::fromIntellicheckDriversLicense($license);
        }

        return new self(
            (string) $license[0]['value'],
            (string) $license[1]['value'],
            (string) $license[2]['value'],
            (string) $license[3]['value'],
            (string) $license[4]['value'],
            (string) $license[5]['value'],
            $license[5]['value'] . ' ' . $license[4]['value'],
            (string) $license[7]['value'],
            (string) $license[8]['value'],
            (string) $license[9]['value'],
            (string) $license[10]['value'],
            (string) $license[11]['value'],
            (string) $license[12]['value'],
            (string) $license[13]['value'],
            (string) $license[14]['value'],
            (string) $license[15]['value'],
            (string) $license[16]['value'],
            (string) $license[17]['value'],
            (string) $license[18]['value'],
            (string) $license[19]['value'],
            (string) $license[20]['value']
        );
    }

    public static function fromIntellicheckDriversLicense(array $license): self
    {
        // Parse the address into components
        $addressLines = explode("\n", trim($license['address'] ?? ''));
        $streetAddress = $addressLines[0] ?? '';
        if (count($addressLines) > 1 && strpos($addressLines[1], 'APT') === 0) {
            $streetAddress .= ' ' . $addressLines[1];
            $cityStateZip = $addressLines[2] ?? '';
        } else {
            $cityStateZip = $addressLines[1] ?? '';
        }

        // Parse city, state, zip from the last address line
        $cityStateZipParts = explode(', ', $cityStateZip);
        $city = $cityStateZipParts[0] ?? '';
        $stateZip = $cityStateZipParts[1] ?? '';
        $stateZipParts = explode(' ', $stateZip);
        $state = $stateZipParts[0] ?? $license['state'] ?? '';
        $zipCode = $stateZipParts[1] ?? '';

        // Format dates
        $birthDate = '';
        if (isset($license['birthday'])) {
            $birthday = $license['birthday'];
            $birthDate = sprintf('%04d-%02d-%02d', $birthday['year'], $birthday['month'], $birthday['day']);
        }

        $expirationDate = '';
        if (isset($license['exp_date'])) {
            $expDate = $license['exp_date'];
            $expirationDate = sprintf('%04d-%02d-%02d', $expDate['year'], $expDate['month'], $expDate['day']);
        } elseif (isset($license['expDate'])) {
            $expDate = $license['expDate'];
            $expirationDate = sprintf('%04d-%02d-%02d', $expDate['year'], $expDate['month'], $expDate['day']);
        }

        // Build full name
        $fullName = trim(($license['firstname'] ?? '') . ' ' . ($license['middlename'] ?? '') . ' ' . ($license['lastname'] ?? ''));
        $fullName = preg_replace('/\s+/', ' ', $fullName); // Remove extra spaces

        return new self(
            region: $state, // Using state as region
            documentNumber: (string) ($license['license'] ?? ''),
            vehicleClassification: '', // Not provided in Intellicheck format
            expirationDate: $expirationDate,
            lastName: (string) ($license['lastname'] ?? ''),
            firstName: (string) ($license['firstname'] ?? ''),
            fullName: $fullName,
            streetAddress: $streetAddress,
            city: $city,
            state: $state,
            zipCode: $zipCode,
            birthDate: $birthDate,
            restriction: '', // Not provided in Intellicheck format
            endorsements: '', // Not provided in Intellicheck format
            sex: '', // Not provided in Intellicheck format
            hairColor: '', // Not provided in Intellicheck format
            eyeColor: '', // Not provided in Intellicheck format
            height: '', // Not provided in Intellicheck format
            weight: '', // Not provided in Intellicheck format
            issueDate: '', // Not provided in Intellicheck format
            documentDiscriminator: '' // Not provided in Intellicheck format
        );
    }
}
