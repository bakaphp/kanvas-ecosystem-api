<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Enums;

enum ConfigurationEnum: string
{
    case APOLLO_API_KEY = 'APOLLO_API_KEY';
    case APOLLO_JOB_SEGMENTS = 'APOLLO_JOB_SEGMENTS';
    case APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS = 'APOLLO_DATA_ENRICHMENT_CUSTOM_FIELDS';
    case APOLLO_COMPANY_REPORTS = 'APOLLO_COMPANY_REPORTS';
    case APOLLO_COMPANY_REPORTS_USERS = 'APOLLO_COMPANY_REPORTS_USERS';
    case APOLLO_REVALIDATION = 'APOLLO_REVALIDATION';
}
