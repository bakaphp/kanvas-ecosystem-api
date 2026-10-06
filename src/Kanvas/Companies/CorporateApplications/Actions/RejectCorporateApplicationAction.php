<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Concerns\ResolvesUpgradeTarget;
use Kanvas\Companies\CorporateApplications\Concerns\ReviewsApplication;
use Kanvas\Companies\CorporateApplications\Concerns\SendsApplicationEmail;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationEmailEnum as Email;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Actions\RemoveCompanyAction;
use Kanvas\Users\Models\Users;

class RejectCorporateApplicationAction
{
    use ResolvesUpgradeTarget;
    use ReviewsApplication;
    use SendsApplicationEmail;

    public function __construct(
        protected readonly Lead $application,
        protected readonly Apps $app,
        protected readonly string $reason,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        $this->assertNotDecided($this->application, CorporateApplicationStatusEnum::REJECTED);

        $this->releaseProvisionalCompany();

        $this->stampReview($this->application, CorporateApplicationStatusEnum::REJECTED, $this->reviewedBy);
        Field::STATUS_REASON->writeTo($this->application, $this->reason);

        return [
            'application' => $this->application->getId(),
            'status' => CorporateApplicationStatusEnum::REJECTED->value,
            'reason' => $this->reason,
            'applicant_notified' => $this->sendRejectionEmail(),
        ];
    }

    private function releaseProvisionalCompany(): void
    {
        $target = $this->upgradeTarget($this->application, $this->app);

        if ($target === null) {
            return;
        }

        $sourceCompanyId = (int) Field::UPGRADE_SOURCE_COMPANY_ID->readFrom($this->application);

        if ($target->company->getId() === $sourceCompanyId) {
            return;
        }

        $branch = $target->company->branch()->first();

        if ($branch !== null) {
            new RemoveCompanyAction($target->user, $branch, $this->app)->execute();
        }

        $target->company->softDelete();
    }

    private function sendRejectionEmail(): bool
    {
        return $this->sendApplicationEmail(
            $this->app,
            Email::REJECTED,
            [
                'lead' => $this->application,
                'reason' => $this->reason,
                'contactName' => Field::contactName($this->application),
            ],
            (string) $this->application->email,
            $this->application,
        );
    }
}
