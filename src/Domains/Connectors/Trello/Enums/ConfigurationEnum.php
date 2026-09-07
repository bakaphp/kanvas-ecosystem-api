<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Trello\Enums;

enum ConfigurationEnum: string
{
    case API_KEY = 'trello_api_key';
    case API_TOKEN = 'trello_api_token';
}
