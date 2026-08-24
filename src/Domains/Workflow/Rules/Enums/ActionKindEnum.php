<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Rules\Enums;

/**
 * Which surface an `actions` row belongs to. Both live in one table because both are discovered from
 * the same `#[WorkflowAction]` attribute, but they are assembled into different things: a workflow
 * activity is picked as a step inside a rule, a receiver job is what an inbound webhook is pointed
 * at. An agent that cannot tell them apart offers a receiver as a rule step.
 */
enum ActionKindEnum: string
{
    case WORKFLOW = 'workflow';
    case RECEIVER = 'receiver';
}
