<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

/**
 * Per-app switches for how a Neuron agent's run itself behaves, as opposed to what it remembers
 * (KnowledgeConfigurationEnum).
 */
enum AgentRunConfigurationEnum: string
{
    case DURABLE_RUNS = 'agent_durable_runs_enabled';

    /** A per-app history cap in tokens; unset or 0 keeps `kanvas.agents.max_history_tokens`. */
    case MAX_HISTORY_TOKENS = 'agent_max_history_tokens';

    /**
     * The model that writes history summaries, when it should not be the agent's own (a thinking model
     * at full price). The provider defaults to the agent's; the app's key for that provider applies.
     */
    case SUMMARY_PROVIDER = 'agent_summary_llm_provider';
    case SUMMARY_MODEL = 'agent_summary_llm_model';

    /**
     * A COMPANY setting: the id of an `agent_llm_configs` row (the admin's LLM configs, with their own
     * key and base URI) that writes summaries for every agent of that company. Wins over the two app
     * settings above. Company-level because LLM configs are company rows.
     */
    case SUMMARY_LLM_CONFIG = 'agent_summary_llm_config_id';

    /**
     * Dynamic tool selection: granted catalog tools and MCP toolkits start a turn out of the prompt and
     * the model finds them through `tool_search`. On by default; `0` sends every tool on every round.
     */
    case TOOL_SEARCH = 'agent_tool_search_enabled';
    /**
     * How hard Gemini thinks before each step of a chat turn. A level ("minimal", "low", "medium",
     * "high") sets `thinkingLevel`; an integer sets `thinkingBudget` for the models that take one. Unset
     * leaves the model's default. An LLM config that carries its own `thinkingConfig` wins.
     */
    case GEMINI_THINKING = 'agent_gemini_thinking';
}
