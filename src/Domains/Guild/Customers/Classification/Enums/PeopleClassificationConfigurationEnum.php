<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Enums;

enum PeopleClassificationConfigurationEnum: string
{
    case ENABLED = 'people_classification_enabled';
    case TAXONOMY = 'people_classification_taxonomy';
    case MIN_CONFIDENCE = 'people_classification_min_confidence';
    case APPLY_TAGS = 'people_classification_apply_tags';
    case LLM_CONFIG = 'people_classification_llm_config_id';
    case MODEL = 'people_classification_model';
}
