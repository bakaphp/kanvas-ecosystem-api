<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Kanvas\Connectors\SalesAssist\Activities\SyncCrmLeadActivity as SalesAssistSyncCrmLeadActivity;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * The old name of the CRM lead sync, kept because released extension builds still send it as
 * `activity_class`. It runs the same sync; the CRM is chosen by the request's `identity.type`.
 * It carries its own attribute because `runWorkflowFromEntity` reads the class's own attributes only.
 *
 * @deprecated Use Kanvas\Connectors\SalesAssist\Activities\SyncCrmLeadActivity. Remove once no
 *             extension in use sends this name.
 */
#[WorkflowAction(
    name: 'Sync CRM Lead (Reynolds, deprecated)',
    description: 'Old name of the SalesAssist Sync CRM Lead, kept for extension builds that still send it.',
    integration: IntegrationsEnum::REYNOLDS,
)]
class SyncCrmLeadActivity extends SalesAssistSyncCrmLeadActivity
{
}
