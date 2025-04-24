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
        return new self(
            (string) $license[0]['value'],
            (string) $license[1]['value'],
            (string) $license[2]['value'],
            (string) $license[3]['value'],
            (string) $license[4]['value'],
            (string) $license[5]['value'],
            $license[5]['value'].' '.$license[4]['value'],
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
}
