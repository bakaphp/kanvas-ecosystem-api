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
    public static function requiredFields(?InsuranceCustomer $customer = null): array
    {
        $required = [
            InsuranceCustomerFieldEnum::FIRST_NAME,
            InsuranceCustomerFieldEnum::LAST_NAME,
            InsuranceCustomerFieldEnum::EMAIL,
            InsuranceCustomerFieldEnum::PHONE,
            InsuranceCustomerFieldEnum::BIRTH_DATE,
            InsuranceCustomerFieldEnum::SEX,
            InsuranceCustomerFieldEnum::MARITAL_STATUS,
            InsuranceCustomerFieldEnum::DOCUMENT_TYPE,
            InsuranceCustomerFieldEnum::DOCUMENT_NUMBER,
            InsuranceCustomerFieldEnum::OCCUPATION,
            InsuranceCustomerFieldEnum::ADDRESS_PROVINCE,
            InsuranceCustomerFieldEnum::ADDRESS_MUNICIPALITY,
            InsuranceCustomerFieldEnum::ADDRESS_SECTOR,
            InsuranceCustomerFieldEnum::ADDRESS_STREET,
            InsuranceCustomerFieldEnum::POLITICALLY_EXPOSED,
        ];

        if ($customer?->isTrue(InsuranceCustomerFieldEnum::POLITICALLY_EXPOSED)) {
            $required[] = InsuranceCustomerFieldEnum::JOB_TITLE;
        }

        return $required;
    }

    public static function map(InsuranceCustomer $customer): array
    {
        return [
            'nombre' => $customer->get(InsuranceCustomerFieldEnum::FIRST_NAME),
            'apellido' => $customer->get(InsuranceCustomerFieldEnum::LAST_NAME),
            'genero' => self::gender($customer),
            'fechaNacimiento' => self::birthDate($customer),
            'tipoDocumento' => self::documentType($customer),
            'numeroDocumento' => $customer->get(InsuranceCustomerFieldEnum::DOCUMENT_NUMBER),
            'telefono' => preg_replace('/\D/', '', (string) $customer->get(InsuranceCustomerFieldEnum::PHONE)),
            'nacionalidad' => $customer->get(InsuranceCustomerFieldEnum::NATIONALITY) ?? '',
            'correo' => $customer->get(InsuranceCustomerFieldEnum::EMAIL),
            'estadoCivil' => self::maritalStatus($customer),
            'ocupacion' => $customer->get(InsuranceCustomerFieldEnum::OCCUPATION),
            'imagenPasaporteUrl' => null,
            'direccion' => self::address($customer),
            'debidaDiligencia' => self::dueDiligence($customer),
        ];
    }

    private static function address(InsuranceCustomer $customer): array
    {
        return [
            'provincia' => $customer->get(InsuranceCustomerFieldEnum::ADDRESS_PROVINCE),
            'municipio' => $customer->get(InsuranceCustomerFieldEnum::ADDRESS_MUNICIPALITY),
            'sector' => $customer->get(InsuranceCustomerFieldEnum::ADDRESS_SECTOR),
            'edificio' => $customer->get(InsuranceCustomerFieldEnum::ADDRESS_BUILDING) ?? '',
            'calle' => $customer->get(InsuranceCustomerFieldEnum::ADDRESS_STREET),
        ];
    }

    private static function dueDiligence(InsuranceCustomer $customer): array
    {
        return [
            'politicamenteExpuesto' => $customer->isTrue(InsuranceCustomerFieldEnum::POLITICALLY_EXPOSED),
            'poseeUnFamiliarPep' => $customer->isTrue(InsuranceCustomerFieldEnum::HAS_PEP_RELATIVE),
            'cargo' => $customer->get(InsuranceCustomerFieldEnum::JOB_TITLE) ?? '',
            'nombreFamiliar' => $customer->get(InsuranceCustomerFieldEnum::PEP_RELATIVE_NAME) ?? '',
            'parentescoFamiliar' => $customer->get(InsuranceCustomerFieldEnum::PEP_RELATIVE_RELATIONSHIP) ?? '',
            'cargoFamiliar' => $customer->get(InsuranceCustomerFieldEnum::PEP_RELATIVE_JOB_TITLE) ?? '',
        ];
    }

    private static function birthDate(InsuranceCustomer $customer): string
    {
        return $customer->get(InsuranceCustomerFieldEnum::BIRTH_DATE) . 'T00:00:00.000Z';
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
