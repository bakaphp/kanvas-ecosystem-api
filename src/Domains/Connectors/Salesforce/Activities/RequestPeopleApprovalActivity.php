<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Activities;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Approvals\Concerns\OpensApprovalWithSupersede;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Enums\PeopleSalesforceSyncApprovalTypeEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
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
 * Runs on Lead::AFTER_RUNNING_RECEIVER. Opens the Salesforce sync gate for the lead's People —
 * `approve_people_salesforce_create` / `approve_people_salesforce_update` — independent of the plain
 * content review (Guild\Customers\Activities\RequestPeopleContentApprovalActivity). Only THIS approval
 * gates the actual push (via PushApprovedPeopleActivity, once approved — the Rule listens on this type,
 * not on `approve_people`). The type is picked by whether the People already has a Salesforce Contact
 * id: a cheap local read, not a re-verification against Salesforce itself (the real push's own
 * UpsertsByExternalId::upsertByExternalId() also checks the record still exists there — this pre-check
 * is an accepted approximation, not worth a second API round trip just to label a string).
 *
 * If the webhook payload carries a `variant_id` (the property is already a Kanvas Product/Variant —
 * ImportSalesforcePropertiesCommand/PullPropertyAction imported it as one), a LeadVariantInterest row
 * is created/reused so the interest survives in Kanvas itself, not only as a Salesforce side effect.
 * Most leads carry no `variant_id` at all (a generic contact form) — that is the common case, not an
 * edge case, and simply skips this part.
 *
 * By default, a pending request blocks a new one of the same type — a later Lead for the same People
 * simply leaves no trace of its own until the earlier one resolves. Set the Rule's own
 * `params: { auto_reject_stale_pending: true }` (Rule.params flows straight into $params, see
 * DynamicRuleWorkflow::execute()) to instead auto-reject the older pending one via
 * HasApprovals::supersedePendingApproval() and open a fresh one.
 */
#[WorkflowAction(name: 'SalesforceRequestPeopleApprovalActivity')]
class RequestPeopleApprovalActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use OpensApprovalWithSupersede;

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
                $lead->company,
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

        $interest = $this->resolveVariantInterest($lead, $app, $company, $params);

        return $this->openOrSupersede(
            $people,
            $this->salesforceSyncApprovalType($people)->value,
            [
                'lead_id' => $lead->getId(),
                'people_id' => $people->getId(),
                'lead_variant_interest_id' => $interest?->getId(),
            ],
            $params,
        );
    }

    private function salesforceSyncApprovalType(People $people): PeopleSalesforceSyncApprovalTypeEnum
    {
        return $people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value)
            ? PeopleSalesforceSyncApprovalTypeEnum::UPDATE
            : PeopleSalesforceSyncApprovalTypeEnum::CREATE;
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
