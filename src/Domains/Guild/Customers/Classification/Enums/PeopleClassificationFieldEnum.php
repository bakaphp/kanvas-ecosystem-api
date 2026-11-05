<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Enums;

enum PeopleClassificationFieldEnum: string
{
    case LEVEL = 'nivel_jerarquico';
    case AREA = 'area_funcional';
    case NORMALIZED_TITLE = 'normalized_title';
    case CONFIDENCE = 'classification_confidence';
    case SOURCE = 'classification_source';
    case SUGGESTED = 'classification_suggested';
    case HASH = 'classification_hash';
}
