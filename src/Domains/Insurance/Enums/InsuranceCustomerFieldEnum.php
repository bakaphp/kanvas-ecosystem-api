<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceCustomerFieldEnum: string
{
    case FIRST_NAME = 'first_name';
    case LAST_NAME = 'last_name';
    case EMAIL = 'email';
    case PHONE = 'phone';
    case BIRTH_DATE = 'birth_date';
    case SEX = 'sex';
    case MARITAL_STATUS = 'marital_status';
    case DOCUMENT_TYPE = 'document_type';
    case DOCUMENT_NUMBER = 'document_number';
    case NATIONALITY = 'nationality';
    case OCCUPATION = 'occupation';
    case COUNTRY_OF_RESIDENCE = 'country_of_residence';
    case ADDRESS_PROVINCE = 'address_province';
    case ADDRESS_MUNICIPALITY = 'address_municipality';
    case ADDRESS_SECTOR = 'address_sector';
    case ADDRESS_BUILDING = 'address_building';
    case ADDRESS_STREET = 'address_street';
    case POLITICALLY_EXPOSED = 'politically_exposed';
    case HAS_PEP_RELATIVE = 'has_pep_relative';
    case JOB_TITLE = 'job_title';
    case PEP_RELATIVE_NAME = 'pep_relative_name';
    case PEP_RELATIVE_RELATIONSHIP = 'pep_relative_relationship';
    case PEP_RELATIVE_JOB_TITLE = 'pep_relative_job_title';

    public function customFieldKey(): string
    {
        return 'insurance_customer_' . $this->value;
    }

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function isBoolean(): bool
    {
        return in_array($this, [self::POLITICALLY_EXPOSED, self::HAS_PEP_RELATIVE], true);
    }
}
