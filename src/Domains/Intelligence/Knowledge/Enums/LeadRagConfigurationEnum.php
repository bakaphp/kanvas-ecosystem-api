<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Enums;

enum LeadRagConfigurationEnum: string
{
    case ENABLED = 'neuron_lead_rag_enabled';
    case COLLECTION = 'neuron_lead_rag_collection';
    case EMBEDDING_MODEL = 'neuron_lead_rag_embedding_model';
    case MAX_MESSAGES = 'neuron_lead_rag_max_messages';
    case RESULT_LIMIT = 'neuron_lead_rag_result_limit';
    case VECTOR_DIMENSION = 'neuron_lead_rag_vector_dimension';
}
