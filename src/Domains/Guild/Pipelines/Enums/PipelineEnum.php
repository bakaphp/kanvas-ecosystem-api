<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Enums;

enum PipelineEnum: string
{
    case STAGE_COUNTER = 'stage_counter';
    case LEAD_COUNTER = 'lead_counter';
}
