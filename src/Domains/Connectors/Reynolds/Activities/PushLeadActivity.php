<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Kanvas\Connectors\SalesAssist\Activities\PushLeadActivity as SalesAssistPushLeadActivity;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;

#[WorkflowAction(
    name: 'Reynolds Push Lead',
    description: 'Pushes the lead into Reynolds so the CRM has it. Outbound one-way sync — it writes to '
        . 'Reynolds and does not bring anything back, and it does not contact the customer. Only useful '
        . 'if this company actually runs Reynolds; several connectors ship a near-identical step, so '
        . 'pick the one matching the CRM the company uses.',
    integration: IntegrationsEnum::REYNOLDS,
)]
class PushLeadActivity extends SalesAssistPushLeadActivity
{
}
