<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Actions;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Silber\Bouncer\Database\Role as SilberRole;

/**
 * Bootstrap the AgentReport Bouncer role on a given app. Truly idempotent —
 * `firstOrCreate` keyed on (name, scope). Bypasses `CreateRoleAction`
 * because that action's validator throws on duplicate name before its
 * inner firstOrCreate ever runs, defeating the idempotency this command
 * needs for safe re-runs on every deploy.
 *
 * Run before any user gets `$user->assign('AgentReport')`. Console command
 * `kanvas:nervous-system:ensure-agent-report-role` wraps this.
 */
class EnsureAgentReportRoleAction
{
    public function __construct(protected readonly Apps $app)
    {
    }

    public function execute(): SilberRole
    {
        Bouncer::useRoleModel(Role::class);

        return Bouncer::role()->firstOrCreate(
            [
                'name' => RolesEnums::AGENT_REPORT->value,
                'scope' => RolesEnums::getScope($this->app),
            ],
            ['title' => 'Agent Report Recipient'],
        );
    }
}
