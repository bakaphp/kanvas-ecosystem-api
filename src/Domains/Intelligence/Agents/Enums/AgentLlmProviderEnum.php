<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AgentLlmProviderEnum: string
{
    case GEMINI = 'gemini';
    case ANTHROPIC = 'anthropic';
    case OPENAI = 'openai';
    case OPENAI_LIKE = 'openai_like';
    case MISTRAL = 'mistral';
    case DEEPSEEK = 'deepseek';
    case XAI = 'xai';
    case OLLAMA = 'ollama';
}
