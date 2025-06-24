<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'prompt_mine_base_url';
    case API_ENV = 'prompt_mine_api_env';
}
