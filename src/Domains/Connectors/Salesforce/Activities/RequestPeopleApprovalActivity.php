<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Activities;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on Lead::AFTER_RUNNING_RECEIVER. Opens an 'approve_people' approval for the lead's People
 * before anything is pushed to Salesforce — the push itself only happens later, via
 * PushApprovedPeopleActivity, once the request is approved (see src/Kanvas/Approvals/CLAUDE.md).
 *
 * If the webhook payload carries a `variant_id` (the property is already a Kanvas Product/Variant —
 * ImportSalesforcePropertiesCommand/PullPropertyAction imported it as one), a LeadVariantInterest row
 * is created/reused so the interest survives in Kanvas itself, not only as a Salesforce side effect.
 * Most leads carry no `variant_id` at all (a generic contact form) — that is the common case, not an
 * edge case, and simply skips this part.
 */
#[WorkflowAction(name: 'SalesforceRequestPeopleApprovalActivity')]
class RequestPeopleApprovalActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param Lead $lead
     */
    #[Override]
    public function execute(Model $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::SALESFORCE,
            additionalParams: $params,
            integrationOperation: fn ($lead, $app, $integrationCompany, $additionalParams) => $this->requestApproval(
                $lead,
                $app,
                $integrationCompany,
                $additionalParams,
            ),
            company: $lead->company,
        );
    }

    private function requestApproval(
        Lead $lead,
        AppInterface $app,
        CompanyInterface $company,
        array $params,
    ): array {
        $people = $lead->people;

        if ($people === null) {
            return ['requested' => false, 'reason' => 'lead has no people'];
        }

        if ($people->pendingApproval() !== null) {
            return ['requested' => false, 'reason' => 'already pending'];
        }

        $interest = $this->resolveVariantInterest($lead, $app, $company, $params);

        $request = $people->requestApproval(
            'approve_people',
            payload: [
                'lead_id' => $lead->getId(),
                'people_id' => $people->getId(),
                'lead_variant_interest_id' => $interest?->getId(),
            ],
            origin: ApprovalOriginEnum::SYSTEM,
        );

        return [
            'requested' => $request !== null,
            'approval_request_id' => $request?->getId(),
            'lead_variant_interest_id' => $interest?->getId(),
        ];
    }

    private function resolveVariantInterest(
        Lead $lead,
        AppInterface $app,
        CompanyInterface $company,
        array $params,
    ): ?LeadVariantInterest {
        /** @var LeadAttempt|null $attempt */
        $attempt = $params['attempt'] ?? null;
        $requestPayload = $attempt?->request ?? [];

        $variantId = $requestPayload['variant_id'] ?? null;

        if (! $variantId) {
            return null;
        }

        try {
            $variant = Variants::getByIdFromCompanyApp((int) $variantId, $company, $app);
        } catch (ModelNotFoundException) {
            return null;
        }

        return LeadVariantInterest::firstOrCreate([
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'leads_id' => $lead->getId(),
            'variants_id' => $variant->getId(),
        ], [
            'users_id' => $lead->users_id,
            'is_active' => true,
        ]);
    }
}
