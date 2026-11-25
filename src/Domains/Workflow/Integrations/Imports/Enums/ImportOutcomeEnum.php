<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Enums;

enum ImportOutcomeEnum: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case SKIPPED = 'skipped';
}
