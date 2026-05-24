<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\DailyLearning\Actions;

use Baka\Contracts\AppInterface;
use Bouncer;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * Resolves the human recipients of the daily-learning digest for a given
 * company. We don't broadcast to everyone — only users that an admin has
 * explicitly assigned the `AgentReport` Bouncer role to, and that are
 * active in the target company.
 *
 * Empty result is normal for tenants that haven't opted any user into the
 * role yet; the digest sender treats that as "skip silently".
 */
class EnumerateAgentReportRecipientsAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
    ) {
    }

    /**
     * @return Collection<int, Users>
     */
    public function execute(): Collection
    {
        // Bouncer scope is app-wide (not company-scoped) — see RolesEnums::getScope.
        // We narrow to the company via the users_associated_apps pivot below.
        Bouncer::scope()->to(RolesEnums::getScope($this->app));

        /** @var Collection<int, Users> $candidates */
        $candidates = Users::query()
            ->whereIs(RolesEnums::AGENT_REPORT->value)
            ->whereHas(
                'companies',
                fn ($q) => $q->where('companies.id', (int) $this->company->getId())
            )
            ->where('is_deleted', 0)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        return $candidates;
    }
}
