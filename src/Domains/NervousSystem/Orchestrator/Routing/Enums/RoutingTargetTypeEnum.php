<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Enums;

/**
 * What a signal can be routed to. Case order is the tie-break when several records matched and the
 * classifier couldn't pick: an open lead is the most specific owner of a customer conversation, its
 * organization the fallback, a project last.
 */
enum RoutingTargetTypeEnum: string
{
    case LEAD = 'lead';
    case ORGANIZATION = 'organization';
    case PROJECT = 'project';
}
