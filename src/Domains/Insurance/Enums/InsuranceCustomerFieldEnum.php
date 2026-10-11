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

    public function customFieldKey(): string
    {
        return 'insurance_customer_' . $this->value;
    }

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
