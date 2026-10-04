<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Enums;

enum KnowledgeConfigurationEnum: string
{
    case ENABLED = 'neuron_lead_rag_enabled';
    case COLLECTION = 'neuron_lead_rag_collection';
    case EMBEDDING_PROVIDER = 'neuron_lead_rag_embedding_provider';
    case EMBEDDING_MODEL = 'neuron_lead_rag_embedding_model';
    case RESULT_LIMIT = 'neuron_lead_rag_result_limit';
    case VECTOR_DIMENSION = 'neuron_lead_rag_vector_dimension';
    case MIN_SCORE = 'neuron_lead_rag_min_score';
    case AGENT_MEMORY_ENABLED = 'agent_memory_enabled';
    case AGENT_MEMORY_INGEST_MIN_CHARS = 'agent_memory_ingest_min_chars';
    case AGENT_MEMORY_RETENTION_DAYS = 'agent_memory_retention_days';
    case AGENT_MEMORY_RESULT_LIMIT = 'agent_memory_result_limit';
}
