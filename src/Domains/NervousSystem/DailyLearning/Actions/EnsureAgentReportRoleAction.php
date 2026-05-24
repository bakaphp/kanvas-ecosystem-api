<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Actions;

use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Silber\Bouncer\Database\Role as SilberRole;

/**
 * Bootstrap the AgentReport Bouncer role on a given app. Idempotent at this
 * layer — `CreateRoleAction` itself validator-rejects on a duplicate name
 * before its inner `firstOrCreate` ever runs, so we look up first and only
 * call create when the row is genuinely missing.
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
        $scope = RolesEnums::getScope($this->app);

        $existing = Role::query()
            ->where('name', RolesEnums::AGENT_REPORT->value)
            ->where('scope', $scope)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return new CreateRoleAction(
            name: RolesEnums::AGENT_REPORT->value,
            title: 'Agent Report Recipient',
            app: $this->app,
        )->execute();
    }
}
