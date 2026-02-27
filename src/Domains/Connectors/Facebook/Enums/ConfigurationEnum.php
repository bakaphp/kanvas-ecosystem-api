<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Facebook\Enums;

enum ConfigurationEnum: string
{
    case APP_ID = 'facebook_app_id';
    case APP_SECRET = 'facebook_app_secret';
    case USER_ACCESS_TOKEN = 'facebook_user_access_token';
    case PAGE_ACCESS_TOKEN = 'facebook_page_access_token';
    case GRAPH_API_VERSION = 'facebook_graph_api_version';
}
