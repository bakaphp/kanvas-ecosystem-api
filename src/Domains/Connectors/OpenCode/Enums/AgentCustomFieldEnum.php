<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Enums;

/**
 * Per-agent configuration. UPPERCASE because these are agent custom fields, as opposed to the
 * lowercase app/company settings on `ConfigurationEnum` — the wider connector convention.
 */
enum AgentCustomFieldEnum: string
{
    /** JSON array of {slug, url, base_branch?, branch_prefix?, rules?, protected_paths?}. */
    case ALLOWED_REPOS = 'CODING_ALLOWED_REPOS';
    case SYSTEM_PROMPT = 'CODING_SYSTEM_PROMPT';

    /**
     * The machine this agent's coding sessions run on. One agent, one machine — the same shape as an
     * OpenClaw/Hermes deployment, so a customer can host the runtime on their own server and Kanvas
     * reaches it over whatever route that machine declares.
     */
    case MACHINE_ID = 'CODING_MACHINE_ID';

    /**
     * A git token for pushing this agent's branches. Per AGENT, never per company: two agents on one
     * box must be able to reach different repositories, and a shared token makes the allow-list
     * cosmetic. Kanvas installs it on the machine when the agent's container comes up, so the customer
     * never has to configure git themselves.
     */
    case GIT_TOKEN = 'CODING_GIT_TOKEN';

    /**
     * The name of the app/company setting holding this agent's provider key, for an agent that should
     * not use the tenant default — a cheaper model for routine work, a separate budget per team, or a
     * key whose spend is attributed to one agent.
     *
     * Naming a setting is the better of the two forms: the secret stays in one place, and rotating it
     * does not mean editing every agent that uses it. `PROVIDER_API_KEY` below takes a literal key
     * instead, for a key that genuinely belongs to this agent alone.
     */
    case PROVIDER_KEY_NAME = 'CODING_PROVIDER_KEY_NAME';

    /** A provider key belonging to this agent alone. Prefer PROVIDER_KEY_NAME where the key is shared. */
    case PROVIDER_API_KEY = 'CODING_PROVIDER_API_KEY';

    /**
     * Lets this agent push straight to a base or protected branch. Off unless set to a truthy value.
     *
     * Deliberately opt-in and per agent: most agents should only ever produce a branch, and the damage
     * from an unwanted branch is deleting it, while the damage from an unwanted trunk push is a rewrite
     * of everyone's history. Turning this on does NOT bypass approval — a human still approves the diff,
     * this only decides whether a trunk is a legal destination for it.
     */
    case ALLOW_TRUNK_PUSH = 'CODING_ALLOW_TRUNK_PUSH';

    /**
     * The identity this agent's commits are authored under, overriding `Agent name <agent@kanvas.dev>`.
     *
     * Deploy platforms (Vercel, Netlify) refuse to build a commit whose author email maps to no member
     * of the team, so an agent pushing to such a repository needs an email the platform recognises.
     * Admin-set, never by the agent: whoever controls this chooses whose name the work goes out under.
     */
    case GIT_AUTHOR_NAME = 'CODING_GIT_AUTHOR_NAME';
    case GIT_AUTHOR_EMAIL = 'CODING_GIT_AUTHOR_EMAIL';

    /**
     * The model this agent codes with, overriding the app's default.
     *
     * Per agent because agents are not interchangeable: a refactoring agent on a large codebase and one
     * that writes small fixes have different needs, and paying the expensive model for both is how a
     * coding budget disappears. Without PROVIDER_ID below it picks a model within the app's provider.
     */
    case MODEL = 'CODING_MODEL';

    /**
     * A provider of this agent's own — a frontend agent on OpenRouter while the rest of the app stays on
     * OpenAI. Setting it switches the whole provider block to the agent: the app's base URL, npm package
     * and env var are ignored, and the agent must carry its own key (PROVIDER_API_KEY or
     * PROVIDER_KEY_NAME), so the app's key is never sent to a provider it was not issued by.
     */
    case PROVIDER_ID = 'CODING_PROVIDER_ID';

    case PROVIDER_BASE_URL = 'CODING_PROVIDER_BASE_URL';
    case PROVIDER_NPM = 'CODING_PROVIDER_NPM';
    case PROVIDER_ENV_VAR = 'CODING_PROVIDER_ENV_VAR';

    /** The port this agent's container listens on. One box, many agents, one port each. */
    case CONTAINER_PORT = 'CODING_CONTAINER_PORT';

    case CONTAINER_PASSWORD = 'CODING_CONTAINER_PASSWORD';
}
