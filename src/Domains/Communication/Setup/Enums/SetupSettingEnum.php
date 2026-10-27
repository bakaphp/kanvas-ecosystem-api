<?php

declare(strict_types=1);

namespace Kanvas\Communication\Setup\Enums;

/**
 * App settings holding what every company is set up from. The lead type config is shared by every
 * dealer; only the Internet lead type differs from the rest.
 */
enum SetupSettingEnum: string
{
    case INTERNET_LEAD_TYPE_CONFIG = 'communication_internet_lead_type_config';
    case OTHER_LEAD_TYPE_CONFIG = 'communication_other_lead_type_config';
    // The pipeline copied to a company the first time its lead types get the follow-up config.
    case FOLLOW_UP_PIPELINE = 'communication_follow_up_pipeline_id';

    public const string INTERNET_LEAD_TYPE = 'Internet';
}
