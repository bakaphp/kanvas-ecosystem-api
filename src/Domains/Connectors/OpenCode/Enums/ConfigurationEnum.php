<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Enums;

/**
 * App/company settings (lowercase), as opposed to agent custom fields (UPPERCASE) — the wider
 * connector convention.
 */
enum ConfigurationEnum: string
{
    case IMAGE = 'opencode_image';
    case NETWORK = 'opencode_network';
    case PROVIDER_ID = 'opencode_provider_id';
    case PROVIDER_BASE_URL = 'opencode_provider_base_url';
    case PROVIDER_API_KEY = 'opencode_provider_api_key';
    case PROVIDER_ENV_VAR = 'opencode_provider_env_var';
    /**
     * Which AI SDK package opencode loads for the provider.
     *
     * `@ai-sdk/openai-compatible` speaks chat completions and works against anything OpenAI-shaped.
     * `@ai-sdk/openai` is OpenAI's own and speaks the **Responses API**, which the codex models require
     * — they reject chat completions outright. Both ship inside the image.
     */
    case PROVIDER_NPM = 'opencode_provider_npm';
    case MODEL = 'opencode_model';
    case WORKSPACE_ROOT = 'opencode_workspace_root';
    /**
     * A coding container often shares a box with other agents, so its ceiling has to be set against
     * what that box actually has free. Left at the default on a busy host, the kernel's OOM killer
     * picks a victim by size across the whole machine — and the neighbours are somebody's agents.
     */
    case CONTAINER_CPUS = 'opencode_container_cpus';
    case CONTAINER_MEMORY = 'opencode_container_memory';
    /** Set to an already-running server to skip launching a container entirely (local development). */
    case STATIC_ENDPOINT = 'opencode_static_endpoint';
    case STATIC_PASSWORD = 'opencode_static_password';
    case MAX_CONCURRENT_SESSIONS = 'coding_max_concurrent_sessions';
    case MAX_SESSION_COST_USD = 'coding_max_session_cost_usd';
}
