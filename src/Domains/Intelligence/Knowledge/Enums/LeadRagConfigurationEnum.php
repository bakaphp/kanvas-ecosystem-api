<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Enums;

/**
 * The one Lead-source-specific knob. All shared knobs (enabled, collection,
 * embedding provider/model, dimension, result limit) moved to
 * KnowledgeConfigurationEnum with their value strings preserved.
 */
enum LeadRagConfigurationEnum: string
{
    case MAX_MESSAGES = 'neuron_lead_rag_max_messages';
}
