<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Intras\Actions\DiffPeopleWithIntrasAction;
use Kanvas\Connectors\Intras\Actions\FindSipgoParticipantAction;
use Kanvas\Connectors\Intras\Activities\Concerns\RequestsSipgoApproval;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Enums\SipgoEntityEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on People UPDATED, CREATE_CUSTOM_FIELD, CREATE_CUSTOM_FIELDS (Control Center) and AGENT_UPDATED
 * (agent tools); one save may fire two of them, and the identical-pending check below collapses that
 * to one request. The SIPGO counterpart of Salesforce's RequestPeopleApprovalActivity, with
 * one difference: the request freezes the from → to change set in its payload, and the push writes
 * exactly that. An approver signs off on values they can see, and a SIPGO pull landing before the
 * decision cannot change what goes out.
 *
 * Because the payload is a snapshot, a newer edit supersedes a pending request by default — leaving
 * the old one blocking would push an outdated snapshot and silently drop the newer edit. The Rule can
 * still set `auto_reject_stale_pending: false`. A pending request whose snapshot equals the new one is
 * left alone, so re-saving an unchanged person does not churn approvals.
 */
#[WorkflowAction(
    name: 'IntrasRequestPeopleApprovalActivity',
    description: 'Opens approve_people_intras_update on a People linked to a SIPGO participant or facilitator '
        . 'when its synced fields differ from SIPGO. The request carries the from → to change set the push will write.',
)]
class RequestPeopleApprovalActivity extends KanvasActivity implements WorkflowActivityInterface
{
    use RequestsSipgoApproval;

    public $tries = 3;

    /**
     * @param People $people
     */
    #[Override]
    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (
            ParticipantMapper::participantId($people) === null
            && SipgoEntityEnum::FACILITATOR->legacyIdOf($people) === null
            && ! ParticipantMapper::isParticipant($people)
        ) {
            return ['requested' => false, 'reason' => 'people is not linked to SIPGO and is not a participant'];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::INTRAS,
            additionalParams: $params,
            integrationOperation: fn ($people, $app, $integrationCompany, $additionalParams) => $this->requestApproval(
                $people,
                $additionalParams,
            ),
            company: $people->company,
        );
    }

    private function requestApproval(People $people, array $params): array
    {
        if (ParticipantMapper::participantId($people) === null && SipgoEntityEnum::FACILITATOR->legacyIdOf($people) === null) {
            $matches = new FindSipgoParticipantAction($people, new Client($people->app))->execute();

            if (count($matches) > 1) {
                return $this->failWorkflow([
                    'requested' => false,
                    'reason' => 'SIPGO has several participants matching this person: ' . implode(', ', $matches) . '; link one by hand',
                ]);
            }

            if ($matches === []) {
                return $this->requestSipgoApproval(
                    $people,
                    PeopleIntrasSyncApprovalTypeEnum::CREATE->value,
                    fn () => [new DiffPeopleWithIntrasAction($people)->createItem()],
                    ['people_id' => $people->getId()],
                    $params,
                );
            }

            // One participant already carries this identification or email: adopt it, then sync
            // as an update. Paused so the stamp does not re-fire the request rules.
            $people->withoutWorkflows(fn () => $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $matches[0]));
        }

        return $this->requestSipgoApproval(
            $people,
            PeopleIntrasSyncApprovalTypeEnum::UPDATE->value,
            fn () => new DiffPeopleWithIntrasAction($people)->execute(),
            ['people_id' => $people->getId()],
            $params,
            sprintf(
                'SIPGO participant %s / facilitator %s not found',
                ParticipantMapper::participantId($people) ?? '-',
                SipgoEntityEnum::FACILITATOR->legacyIdOf($people) ?? '-',
            ),
        );
    }
}
