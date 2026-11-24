<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Intras\Actions\DiffOrganizationWithIntrasAction;
use Kanvas\Connectors\Intras\Activities\Concerns\RequestsSipgoApproval;
use Kanvas\Connectors\Intras\Enums\OrganizationIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Mappers\OrganizationMapper;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on Organization UPDATED, CREATE_CUSTOM_FIELD, CREATE_CUSTOM_FIELDS and AGENT_UPDATED. The
 * Organization twin of RequestPeopleApprovalActivity: the request freezes the from → to change set,
 * a newer edit supersedes a pending request, and an identical pending request is left alone.
 */
#[WorkflowAction(
    name: 'IntrasRequestOrganizationApprovalActivity',
    description: 'Opens approve_organization_intras_update on an Organization linked to a SIPGO company when '
        . 'its synced fields differ from SIPGO. The request carries the from → to change set the push will write.',
)]
class RequestOrganizationApprovalActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use RequestsSipgoApproval;

    public $tries = 3;

    /**
     * @param Organization $organization
     */
    #[Override]
    public function execute(Model $organization, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (OrganizationMapper::companyId($organization) === null) {
            return ['requested' => false, 'reason' => 'organization is not linked to a SIPGO company'];
        }

        return $this->executeIntegration(
            entity: $organization,
            app: $app,
            integration: IntegrationsEnum::INTRAS,
            additionalParams: $params,
            integrationOperation: fn ($organization, $app, $integrationCompany, $additionalParams) => $this->requestApproval(
                $organization,
                $additionalParams,
            ),
            company: $organization->company,
        );
    }

    private function requestApproval(Organization $organization, array $params): array
    {
        return $this->requestSipgoApproval(
            $organization,
            OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value,
            fn () => new DiffOrganizationWithIntrasAction($organization)->execute(),
            ['organization_id' => $organization->getId()],
            $params,
            'SIPGO company ' . OrganizationMapper::companyId($organization) . ' not found',
        );
    }
}
