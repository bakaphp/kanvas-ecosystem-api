<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Enums;

use Baka\Contracts\CompanyInterface;

enum ConfigurationEnum: string
{
    case BASE_URL = 'dhl_base_url';
    case USERNAME = 'dhl_username';
    case PASSWORD = 'dhl_password';
    case ACCOUNT_NUMBER = 'dhl_account_number';
    case SIGNATURE_SERVICE = 'dhl_signature_service';
    case LABEL_TEMPLATE = 'dhl_label_template';
    case ORIGIN = 'dhl_origin';
    case REQUEST_PICKUP = 'dhl_request_pickup';
    case PICKUP_CLOSE_TIME = 'dhl_pickup_close_time';
    case CONTENT_DESCRIPTION = 'dhl_content_description';
    case INCOTERM = 'dhl_incoterm';

    public const string DEFAULT_BASE_URL = 'https://express.api.dhl.com/mydhlapi';
    public const string TEST_BASE_URL = 'https://express.api.dhl.com/mydhlapi/test';
    public const string DEFAULT_SIGNATURE_SERVICE = 'SD';
    public const string DEFAULT_LABEL_TEMPLATE = 'ECOM26_84_A4_001';
    public const string DEFAULT_PICKUP_CLOSE_TIME = '18:00';
    public const string DEFAULT_CONTENT_DESCRIPTION = 'Merchandise';
    public const string DEFAULT_INCOTERM = 'DAP';
    public const string COMMERCIAL_INVOICE_TEMPLATE = 'COMMERCIAL_INVOICE_P_10';
    public const string API_VERSION = '3.4.1';

    public function default(): ?string
    {
        return match ($this) {
            self::BASE_URL => self::DEFAULT_BASE_URL,
            self::SIGNATURE_SERVICE => self::DEFAULT_SIGNATURE_SERVICE,
            self::LABEL_TEMPLATE => self::DEFAULT_LABEL_TEMPLATE,
            self::PICKUP_CLOSE_TIME => self::DEFAULT_PICKUP_CLOSE_TIME,
            self::CONTENT_DESCRIPTION => self::DEFAULT_CONTENT_DESCRIPTION,
            self::INCOTERM => self::DEFAULT_INCOTERM,
            default => null,
        };
    }

    public function valueFor(CompanyInterface $company): string
    {
        return (string) ($company->get($this->value) ?: $this->default());
    }
}
