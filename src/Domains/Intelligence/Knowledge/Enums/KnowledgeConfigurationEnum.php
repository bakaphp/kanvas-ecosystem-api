<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Enums;

/**
 * Single config source for the shared knowledge store (entity-scoped Lead RAG
 * AND tenant-scoped company docs read the same knobs). The string VALUES are
 * intentionally kept as the legacy `neuron_lead_rag_*` per-app setting keys so
 * apps already running Lead RAG keep their configuration with no migration —
 * only the enum case names are neutralized. EMBEDDING_PROVIDER is the one new
 * knob (defaults to gemini in the embedder).
 */
enum KnowledgeConfigurationEnum: string
{
    case ENABLED = 'neuron_lead_rag_enabled';
    case COLLECTION = 'neuron_lead_rag_collection';
    case EMBEDDING_PROVIDER = 'neuron_lead_rag_embedding_provider';
    case EMBEDDING_MODEL = 'neuron_lead_rag_embedding_model';
    case RESULT_LIMIT = 'neuron_lead_rag_result_limit';
    case VECTOR_DIMENSION = 'neuron_lead_rag_vector_dimension';
    case MIN_SCORE = 'neuron_lead_rag_min_score';
}
