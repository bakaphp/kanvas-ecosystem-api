<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Jobs\MigrateCorporateUserVariantsJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * The only two parts of corporate approval that are actually Movipass: which region the new
 * company operates in, and moving the applicant's vehicles/TAGs into it.
 *
 * Runs on `corporate-application-approved`, which the generic approval fires — the core flow
 * has no knowledge of this connector.
 */
#[WorkflowAction]
class SetupApprovedCorporateCompanyActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) {
                /** @var Lead $lead */
                $companyId = (int) Field::COMPANY_ID->readFrom($lead);

                if ($companyId === 0) {
                    return ['lead' => $lead->getId(), 'status' => 'skipped', 'reason' => 'no company on the application'];
                }

                $company = Companies::getById($companyId);

                $this->assignRegion($lead, $company);

                return [
                    'lead' => $lead->getId(),
                    'status' => 'done',
                    'company_id' => $company->getId(),
                    'variants_migration_dispatched' => $this->migrateVariants($lead, $company, $app),
                ];
            },
            company: $lead->company,
        );
    }

    private function assignRegion(Lead $lead, Companies $company): void
    {
        $regionId = $lead->get('region_id');

        if (! empty($regionId)) {
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, $regionId);

            return;
        }

        if (app()->bound(Regions::class)) {
            $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, app(Regions::class)->getId());
        }
    }

    /**
     * Only the upgrade path has products to move: a brand-new corporate account has none, and
     * the applicant has no user yet.
     */
    private function migrateVariants(Lead $lead, Companies $company, AppInterface $app): bool
    {
        $userId = (int) Field::UPGRADE_USER_ID->readFrom($lead);
        $sourceCompanyId = (int) Field::UPGRADE_SOURCE_COMPANY_ID->readFrom($lead);

        if ($userId === 0 || $sourceCompanyId === 0 || $sourceCompanyId === $company->getId()) {
            return false;
        }

        dispatch(new MigrateCorporateUserVariantsJob(
            app: $app instanceof Apps ? $app : app(Apps::class),
            userId: $userId,
            sourceCompanyId: $sourceCompanyId,
            targetCompanyId: $company->getId(),
        ));

        return true;
    }
}
