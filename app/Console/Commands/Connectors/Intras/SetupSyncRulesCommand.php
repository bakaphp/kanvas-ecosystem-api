<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Enums\ApprovalNotifyModeEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Activities\PushApprovedOrganizationActivity;
use Kanvas\Connectors\Intras\Activities\PushApprovedPeopleActivity;
use Kanvas\Connectors\Intras\Activities\RequestOrganizationApprovalActivity;
use Kanvas\Connectors\Intras\Activities\RequestPeopleApprovalActivity;
use Kanvas\Connectors\Intras\Enums\OrganizationIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;
use Kanvas\Workflow\Rules\Enums\RuleConditionOperatorEnum;

/**
 * Wires the approval-gated Kanvas → SIPGO sync for every company with an `intras` integration row
 * (or the companies given). Per company: the People and Organization approval policies, a request
 * rule on each edit event (People also on CREATED, for the create lane), and a push rule on
 * ApprovalRequest APPROVED per approval type. Idempotent.
 *
 * Rollout order: `kanvas:workflow-sync-actions` (Action rows for the four activities) → the `intras`
 * integration row per company → this command. Spec: docs/sipgo/sipgo-push-back-spec.md.
 */
class SetupSyncRulesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-setup-sync-rules
                            {app_id : The Intras app}
                            {--company=* : Company ids; defaults to every company with an active intras integration}';

    protected $description = 'Wire the approval policies and workflow rules that push Kanvas edits back to SIPGO';

    private const array EDIT_EVENTS = [
        WorkflowEnum::UPDATED,
        WorkflowEnum::CREATE_CUSTOM_FIELD,
        WorkflowEnum::CREATE_CUSTOM_FIELDS,
        WorkflowEnum::AGENT_UPDATED,
    ];

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyIds = array_map('intval', $this->option('company')) ?: $this->intrasCompanyIds($app);

        if ($companyIds === []) {
            $this->error('No company has an active intras integration on app ' . $app->getId() . '; pass --company or set the integration up first.');

            return self::FAILURE;
        }

        foreach ($companyIds as $companyId) {
            if ($this->call('kanvas:approvals:seed-people-policy', [
                'apps_id' => $app->getId(),
                'company_id' => $companyId,
                '--connector' => 'intras',
                '--notify' => ApprovalNotifyModeEnum::DIGEST->value,
            ]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            $this->seedOrganizationPolicies($app, $companyId);
            $wires = $this->rules($app, $companyId);

            foreach ($wires as $wire) {
                if ($wire->execute() === null) {
                    $this->error(sprintf(WireRuleAction::MISSING_ACTION, 'an Intras sync activity'));

                    return self::FAILURE;
                }
            }

            $this->info(sprintf('Company %d: policies and %d rules in place.', $companyId, count($wires)));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function intrasCompanyIds(Apps $app): array
    {
        return IntegrationsCompany::query()
            ->where('apps_id', $app->getId())
            ->where('integrations_id', Integrations::getByName(IntegrationsEnum::INTRAS->value)->getId())
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->pluck('companies_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * `digest`: one Control Center save can open several requests across people, and an import-sized
     * batch would otherwise mail the owner once per request.
     */
    private function seedOrganizationPolicies(Apps $app, int $companyId): void
    {
        $systemModuleId = SystemModulesRepository::getByModelName(Organization::class, $app)->getId();

        foreach (OrganizationIntrasSyncApprovalTypeEnum::cases() as $approvalType) {
            ApprovalPolicy::firstOrCreate([
                'apps_id' => $app->getId(),
                'companies_id' => $companyId,
                'system_modules_id' => $systemModuleId,
                'approval_type' => $approvalType->value,
            ], [
                'steps' => [['step' => 1, 'resolver' => 'company_owner', 'config' => [], 'required_approvals' => 1]],
                'handler' => null,
                'trigger' => ApprovalTriggerEnum::MANUAL,
                'reject_policy' => 'any',
                'notify' => ApprovalNotifyModeEnum::DIGEST->value,
                'allow_authority_override' => false,
                'expires_after_hours' => null,
            ]);
        }
    }

    /**
     * @return list<WireRuleAction>
     */
    private function rules(Apps $app, int $companyId): array
    {
        $wires = [];

        foreach ([
            [People::class, RequestPeopleApprovalActivity::class, 'People', [WorkflowEnum::CREATED, ...self::EDIT_EVENTS]],
            [Organization::class, RequestOrganizationApprovalActivity::class, 'Organization', self::EDIT_EVENTS],
        ] as [$entity, $activity, $label, $events]) {
            foreach ($events as $event) {
                $wires[] = new WireRuleAction(
                    $app,
                    $companyId,
                    $entity,
                    $event->value,
                    $activity,
                    sprintf('Intras: request %s sync approval (%s)', $label, $event->value),
                    sprintf('Opens a SIPGO sync approval when a %s linked to SIPGO is edited.', $label),
                );
            }
        }

        foreach ([
            [PushApprovedPeopleActivity::class, PeopleIntrasSyncApprovalTypeEnum::UPDATE->value, 'People update'],
            [PushApprovedPeopleActivity::class, PeopleIntrasSyncApprovalTypeEnum::CREATE->value, 'People create'],
            [PushApprovedOrganizationActivity::class, OrganizationIntrasSyncApprovalTypeEnum::UPDATE->value, 'Organization update'],
        ] as [$activity, $approvalType, $label]) {
            $wires[] = new WireRuleAction(
                $app,
                $companyId,
                ApprovalRequest::class,
                WorkflowEnum::APPROVED->value,
                $activity,
                sprintf('Intras: push approved %s to SIPGO', $label),
                sprintf('Writes an approved %s change set to SIPGO.', $label),
                [['attribute_name' => 'approval_type', 'operator' => RuleConditionOperatorEnum::EQUAL, 'value' => $approvalType]],
            );
        }

        return $wires;
    }
}
