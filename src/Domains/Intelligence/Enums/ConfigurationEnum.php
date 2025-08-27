<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'Intelligence';
    case GEMINI_KEY = 'kanvas-intelligence-gemini-key';
    case GEMINI_MODEL = 'kanvas-intelligence-gemini-model';
    case OPEN_AI_EMBEDDINGS_KEY = 'kanvas-intelligence-openai-embeddings-key';
    case OPEN_AI_EMBEDDINGS_MODEL = 'kanvas-intelligence-openai-embeddings-model';
    //PineconeVectorStore
    case PINECONE_API_KEY = 'kanvas-intelligence-pinecone-api-key';
    case PINECONE_INDEX_URL = 'kanvas-intelligence-pinecone-index-url';
    case ADK_BASE_URL = 'google_orchestrator_base_url';
    case ADK_API_KEY = 'google_orchestrator_api_key';
    case ADK_APP_NAME = 'google_orchestrator_app_name';

    case AGENT_HAND_OFF = 'agent_hand_off';
}
