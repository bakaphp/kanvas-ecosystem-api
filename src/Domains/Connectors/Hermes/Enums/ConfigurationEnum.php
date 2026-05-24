<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Enums;

enum ConfigurationEnum: string
{
    case SSH_HOST = 'hermes_ssh_host';
    case SSH_PORT = 'hermes_ssh_port';
    case SSH_USER = 'hermes_ssh_user';
    case SSH_PRIVATE_KEY = 'hermes_ssh_private_key';
    case HERMES_HOME = 'hermes_home';
    case CLI_PATH = 'hermes_cli_path';
    case CONFIG_FILENAME = 'hermes_config_filename';
    case GATEWAY_TOKEN = 'hermes_gateway_token';
    case DOCKERFILE_TEMPLATE = 'hermes_dockerfile_template';
    case DEFAULT_ENVIRONMENT = 'hermes_default_environment';
    case DEFAULT_MACHINE_ID = 'hermes_default_machine_id';
    case DEFAULT_MODEL = 'hermes_default_model';
    case GEMINI_API_KEY = 'hermes_gemini_api_key';
    case GOOGLE_API_KEY = 'hermes_google_api_key';
    case ANTHROPIC_API_KEY = 'hermes_anthropic_api_key';
    case SLACK_WEBHOOK_URL = 'hermes_slack_webhook_url';
    case ALERT_EMAIL = 'hermes_alert_email';
    case SHARED_IMAGE_NAME = 'hermes_shared_image_name';
    case SHARED_IMAGE_DIR = 'hermes_shared_image_dir';

    // Full upstream image ref, e.g. `nousresearch/hermes-agent:2026.4.1`.
    // When set, overrides the compile-time pin in DockerComposeBuilderService. Lets us
    // bump the pin from app config without redeploying — set the new ref, then
    // re-launch (or in the future, update) the affected agents.
    case BASE_IMAGE = 'hermes_base_image';

    // Slack platform config — merged into `platforms.slack:` in the agent's config.yaml.
    // Per https://hermes-agent.nousresearch.com/docs/user-guide/messaging/slack, this controls
    // thread/channel reply behavior, mention requirements, per-channel prompts, etc.
    // Stored as an associative array (e.g. ['reply_in_thread' => false, 'require_mention' => true]).
    // Defaults applied when unset live in DockerComposeBuilderService::DEFAULT_SLACK_CONFIG.
    case SLACK_CONFIG = 'hermes_slack_config';

    // Telegram platform config — merged into `platforms.telegram.extra:` in config.yaml.
    // Per https://hermes-agent.nousresearch.com/docs/user-guide/messaging/telegram, this is
    // where group `allow_from`, `require_mention`, `mention_patterns`, `dm_topics`,
    // `disable_link_previews`, `reactions`, etc. live. The bot token + minimum allow-list
    // are per-agent (see AgentChannelTokenEnum) so the block here only carries behavior
    // tuning. We emit the block only when set — Hermes does not require it.
    case TELEGRAM_CONFIG = 'hermes_telegram_config';
}
