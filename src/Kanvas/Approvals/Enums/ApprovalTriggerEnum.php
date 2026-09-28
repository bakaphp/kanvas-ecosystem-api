<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

/**
 * What causes a policy to open a request. MANUAL means the intake path calls requestApproval()
 * itself; the rest are opened by the model's own lifecycle so no intake path has to remember.
 */
enum ApprovalTriggerEnum: string
{
    case MANUAL = 'manual';
    case ON_CREATE = 'on_create';
    case ON_UPDATE = 'on_update';
    case ON_EVENT = 'on_event';
}
