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
}
