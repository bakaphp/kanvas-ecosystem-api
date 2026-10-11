<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Mappers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\Enums\CustomerDocumentTypeEnum;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;
use Kanvas\Insurance\Enums\MaritalStatusEnum;
use Kanvas\Insurance\Enums\SexEnum;

class ClienteMapper
{
    public static function requiredFields(): array
    {
        return [
            InsuranceCustomerFieldEnum::FIRST_NAME,
            InsuranceCustomerFieldEnum::LAST_NAME,
            InsuranceCustomerFieldEnum::EMAIL,
            InsuranceCustomerFieldEnum::PHONE,
            InsuranceCustomerFieldEnum::BIRTH_DATE,
            InsuranceCustomerFieldEnum::SEX,
            InsuranceCustomerFieldEnum::MARITAL_STATUS,
            InsuranceCustomerFieldEnum::DOCUMENT_TYPE,
            InsuranceCustomerFieldEnum::DOCUMENT_NUMBER,
            InsuranceCustomerFieldEnum::NATIONALITY,
            InsuranceCustomerFieldEnum::OCCUPATION,
            InsuranceCustomerFieldEnum::COUNTRY_OF_RESIDENCE,
        ];
    }

    public static function map(InsuranceCustomer $customer): array
    {
        return [
            'nombre' => $customer->get(InsuranceCustomerFieldEnum::FIRST_NAME),
            'apellido' => $customer->get(InsuranceCustomerFieldEnum::LAST_NAME),
            'genero' => self::gender($customer),
            'fechaNacimiento' => $customer->get(InsuranceCustomerFieldEnum::BIRTH_DATE),
            'tipoDocumento' => self::documentType($customer),
            'numeroDocumento' => $customer->get(InsuranceCustomerFieldEnum::DOCUMENT_NUMBER),
            'telefono' => preg_replace('/\D/', '', (string) $customer->get(InsuranceCustomerFieldEnum::PHONE)),
            'nacionalidad' => $customer->get(InsuranceCustomerFieldEnum::NATIONALITY),
            'correo' => $customer->get(InsuranceCustomerFieldEnum::EMAIL),
            'estadoCivil' => self::maritalStatus($customer),
            'ocupacion' => $customer->get(InsuranceCustomerFieldEnum::OCCUPATION),
            'paisResidencia' => $customer->get(InsuranceCustomerFieldEnum::COUNTRY_OF_RESIDENCE),
        ];
    }

    private static function gender(InsuranceCustomer $customer): string
    {
        return match (SexEnum::tryFrom((string) $customer->get(InsuranceCustomerFieldEnum::SEX))) {
            SexEnum::MALE => 'M',
            SexEnum::FEMALE => 'F',
            default => throw new ValidationException('This insurer needs the sex to be male or female'),
        };
    }

    private static function maritalStatus(InsuranceCustomer $customer): string
    {
        return match (MaritalStatusEnum::tryFrom((string) $customer->get(InsuranceCustomerFieldEnum::MARITAL_STATUS))) {
            MaritalStatusEnum::SINGLE => 'S',
            MaritalStatusEnum::MARRIED => 'C',
            default => throw new ValidationException('Unsupported marital status for this insurer'),
        };
    }

    private static function documentType(InsuranceCustomer $customer): string
    {
        return match (CustomerDocumentTypeEnum::tryFrom((string) $customer->get(InsuranceCustomerFieldEnum::DOCUMENT_TYPE))) {
            CustomerDocumentTypeEnum::NATIONAL_ID => 'Cedula',
            CustomerDocumentTypeEnum::PASSPORT => 'Pasaporte',
            default => throw new ValidationException('Unsupported document type for this insurer'),
        };
    }
}
