<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

/**
 * The `change_type` discriminator carried on every `plan.changed` Pusher
 * broadcast. Frontends switch on this to route plan vs task changes
 * without having to derive intent from the payload shape.
 */
enum PlanChangeTypeEnum: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case APPROVED = 'approved';

    /** A plan gained an executing agent — the moment its work should start. */
    case ASSIGNED = 'assigned';
    case REJECTED = 'rejected';
    case DELETED = 'deleted';
    case TASK_ADDED = 'task_added';
    case TASK_STATUS_CHANGED = 'task_status_changed';
}
