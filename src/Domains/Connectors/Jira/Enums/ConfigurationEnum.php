<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jira\Enums;

enum ConfigurationEnum: string
{
    case INSTANCE_URL = 'jira_instance_url';
    case EMAIL = 'jira_email';
    case API_TOKEN = 'jira_api_token';

    // Optional defaults so a rule can omit them on every call.
    case DEFAULT_PROJECT_KEY = 'jira_default_project_key';
    case DEFAULT_ISSUE_TYPE = 'jira_default_issue_type';
}
