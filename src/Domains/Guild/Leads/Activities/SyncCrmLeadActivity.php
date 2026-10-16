<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Actions\SyncCrmLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\Exceptions\CrmLeadSyncException;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Brings a CRM customer and prospect into Kanvas. Called through `runWorkflowFromEntity` with this
 * class as `activity_class` and no entity (`entity_id` 0); the request is the `params`:
 *
 *   request_id   string, required. The same id sent twice gives the same result.
 *   identity     { type, client_id, lead_id }, type and client_id required. The ids are the CRM's own.
 *                A lead is never created without lead_id: with it null the call opens the customer's
 *                most recent active lead, and if there is none it only finds or creates the people
 *                (outcome people_only, lead null). Participants and followers need a lead and are
 *                reported as skipped (lead_required) then.
 *   branch_id    Kanvas branch id, optional; the caller's current branch otherwise.
 *   customer     { firstname, middlename, lastname, language, phones{cell,home,work}, emails[],
 *                  address{address,address_2,city,state,zip}, consent{text_opt_status, opt_out,
 *                  opt_out_use, follow_up{phone,mail,email,text}} }
 *   lead         { type{id,name}, source{id,name}, status, salesperson{id,name}, description }
 *   participants { add[{firstname,lastname,phone,email,relationship_id}], remove[{people_id,relationship_id}] }
 *   followers    { attach[email], detach[email] }
 *
 * Every section is optional and a null means "the CRM did not give it": nothing is cleared.
 *
 * The answer is `outcome` (opened, adopted, created_lead, created_all, people_only), `people` and `lead`
 * (null for people_only) as `{id, uuid}`, `people_created`, `lead_created`, `replayed` and `warnings[{code, field}]`. A refusal
 * is thrown with `extensions.reason` and `extensions.field`, which `runWorkflowFromEntity` passes on.
 *
 * Deliberately not wrapped in `executeIntegration`: it turns a thrown error into a status array and
 * the caller would lose the reason.
 */
#[WorkflowAction(
    name: 'Sync CRM Lead',
    description: 'Finds or creates the customer and the lead a CRM\'s own ids point at, then applies what the CRM '
        . 'sent: contacts, address, consent, owner, participants and followers. Meant to be called by a client '
        . 'with the request as params; the entity it runs on is ignored.',
    integration: IntegrationsEnum::INTERNAL,
)]
class SyncCrmLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * The company is the caller's own, never the entity's: `runWorkflowFromEntity` loads whatever
     * entity id it is given, and a lead of another company must not choose the company this runs as.
     */
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $user = $params['user'] ?? null;

        if (! $user instanceof Users) {
            throw CrmLeadSyncException::validationFailed('The request needs an authenticated user', 'user');
        }

        $result = new SyncCrmLeadAction(
            CrmLeadSync::from(
                $app,
                $user,
                $user->getCurrentCompany(),
                $params
            )
        )->execute();

        return [
            'outcome' => $result->outcome->value,
            'people' => ['id' => $result->people->getId(), 'uuid' => $result->people->uuid],
            'lead' => $result->lead === null ? null : ['id' => $result->lead->getId(), 'uuid' => $result->lead->uuid],
            'people_created' => $result->peopleCreated,
            'lead_created' => $result->leadCreated,
            'replayed' => $result->replayed,
            'warnings' => $result->warnings,
        ];
    }
}
