<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Concerns;

use Kanvas\Connectors\Contracts\DefaultRule;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Connectors\SalesAssist\Activities\PullPeopleActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * Every dealer CRM answers a pull through the SalesAssist dispatcher, which picks the vendor from
 * the company's own configuration — so all of them declare the same two rules.
 */
trait DeclaresSalesAssistPullRules
{
    public function defaultRules(): array
    {
        return [
            new DefaultRule(
                trigger: WorkflowEnum::PULL,
                model: Lead::class,
                activity: PullLeadActivity::class,
                name: 'SalesAssist pull lead from CRM',
            ),
            new DefaultRule(
                trigger: WorkflowEnum::PULL,
                model: People::class,
                activity: PullPeopleActivity::class,
                name: 'SalesAssist pull people from CRM',
            ),
        ];
    }
}
