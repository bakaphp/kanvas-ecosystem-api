<?php

declare(strict_types=1);

namespace Kanvas\Companies\CorporateApplications\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Concerns\SendsApplicationEmail;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Actions\RemoveCompanyAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

/**
 * A rejection always reaches the applicant with its reason (§13.3). The app can point
 * `corporate_application_rejected_template` at its own wording; otherwise the shipped
 * `corporate-rejected` template (database/data/movipass_corporate_email_templates.json) is used.
 *
 * An approved application cannot be rejected: its Company and invite are live and in use, and
 * unwinding them is an account deletion, not a review decision. The one provisioning that does
 * happen before review — the upgrade path creates the corporate Company up front so the user
 * can be attached to it — is released here, so a rejected upgrade leaves no empty corporate
 * company in the user's switcher.
 */
class RejectCorporateApplicationAction
{
    use SendsApplicationEmail;

    public const string DEFAULT_TEMPLATE = 'corporate-rejected';

    public function __construct(
        protected readonly Model $application,
        protected readonly Apps $app,
        protected readonly string $reason,
        protected readonly ?Users $reviewedBy = null,
    ) {
    }

    public function execute(): array
    {
        if (Field::STATUS->readFrom($this->application) === CorporateApplicationStatusEnum::APPROVED->value) {
            throw new ValidationException('An approved application cannot be rejected.');
        }

        $this->releaseProvisionalCompany();

        Field::STATUS->writeTo($this->application, CorporateApplicationStatusEnum::REJECTED->value);
        Field::STATUS_REASON->writeTo($this->application, $this->reason);

        if ($this->reviewedBy) {
            Field::REVIEWED_BY->writeTo($this->application, (string) $this->reviewedBy->getId());
            Field::REVIEWED_AT->writeTo($this->application, now()->toIso8601String());
        }

        return [
            'application' => $this->application->getId(),
            'status' => CorporateApplicationStatusEnum::REJECTED->value,
            'reason' => $this->reason,
            'applicant_notified' => $this->sendRejectionEmail(),
        ];
    }

    /**
     * Only the upgrade path has a Company before approval (EnableCorporateModeAction creates it
     * when the request is filed). It holds nothing but onboarding defaults, so it is detached
     * from the user and soft-deleted; a fresh request provisions a fresh one.
     */
    private function releaseProvisionalCompany(): void
    {
        $companyId = (int) Field::COMPANY_ID->readFrom($this->application);
        $userId = (int) Field::UPGRADE_USER_ID->readFrom($this->application);
        $sourceCompanyId = (int) Field::UPGRADE_SOURCE_COMPANY_ID->readFrom($this->application);

        if ($companyId === 0 || $userId === 0 || $companyId === $sourceCompanyId) {
            return;
        }

        $company = Companies::query()->where('id', $companyId)->notDeleted()->first();

        if ($company === null) {
            return;
        }

        $user = Users::getById($userId);
        $branch = $company->branch()->first();
        $associated = UsersAssociatedApps::query()
            ->where('users_id', $user->getId())
            ->where('companies_id', $company->getId())
            ->where('apps_id', $this->app->getId())
            ->exists();

        if ($branch !== null && $associated) {
            new RemoveCompanyAction($user, $branch, $this->app)->execute();
        }

        $company->softDelete();
    }

    private function sendRejectionEmail(): bool
    {
        return $this->sendApplicationEmail(
            $this->app,
            (string) (Setting::REJECTED_TEMPLATE->readFrom($this->app) ?: self::DEFAULT_TEMPLATE),
            'Sobre tu solicitud corporativa',
            [
                'lead' => $this->application,
                'reason' => $this->reason,
                'contactName' => $this->application->get('contact_name') ?? $this->application->firstname,
            ],
            (string) $this->application->email,
            $this->application,
        );
    }
}
