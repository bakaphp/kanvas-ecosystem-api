<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Enums;

enum ConfigurationEnum: string
{
    case SSH_HOST = 'openclaw_ssh_host';
    case SSH_PORT = 'openclaw_ssh_port';
    case SSH_USER = 'openclaw_ssh_user';
    case SSH_PRIVATE_KEY = 'openclaw_ssh_private_key';
    case OPENCLAW_HOME = 'openclaw_home';
    case CLI_PATH = 'openclaw_cli_path';
    case CONFIG_FILENAME = 'openclaw_config_filename';
    case GATEWAY_TOKEN = 'openclaw_gateway_token';
    case DOCKERFILE_TEMPLATE = 'openclaw_dockerfile_template';
    case DEFAULT_ENVIRONMENT = 'openclaw_default_environment';
    case DEFAULT_MACHINE_ID = 'openclaw_default_machine_id';
    case DEFAULT_MODEL = 'openclaw_default_model';
    case GEMINI_API_KEY = 'openclaw_gemini_api_key';
    case GOOGLE_API_KEY = 'openclaw_google_api_key';
    case ANTHROPIC_API_KEY = 'openclaw_anthropic_api_key';
    case SLACK_BOT_TOKEN = 'openclaw_slack_bot_token';
    case SLACK_APP_TOKEN = 'openclaw_slack_app_token';
}
