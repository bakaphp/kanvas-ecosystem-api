<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Jobs\MigrateCorporateUserVariantsJob;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Models\Users;

/**
 * Second half of the corporate upgrade, run when an admin approves. `is_corporate` on the
 * Company is the only switch that grants corporate privilege (PasoRapido limits, tag access,
 * RNC on invoices), so EnableCorporateModeAction deliberately leaves it unset until here.
 *
 * The source company is passed in rather than read from the user: the request and the
 * approval are separate requests, and the user may have switched companies in between.
 */
class GrantCorporateModeAction
{
    public function __construct(
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly Apps $app,
        protected readonly int $sourceCompanyId,
    ) {
    }

    public function execute(): Companies
    {
        $this->company->set('is_corporate', true);
        $this->user->set('is_corporate', true);

        $branch = $this->company->branch()->firstOrFail();
        new SwitchCompanyBranchAction($this->user, $branch->getId())->execute();

        dispatch(new MigrateCorporateUserVariantsJob(
            app: $this->app,
            userId: $this->user->getId(),
            sourceCompanyId: $this->sourceCompanyId,
            targetCompanyId: $this->company->getId(),
        ));

        return $this->company;
    }
}
