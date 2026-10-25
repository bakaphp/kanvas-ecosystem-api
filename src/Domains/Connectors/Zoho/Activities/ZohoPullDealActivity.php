<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Activities;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\PullZohoDealAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\DataTransferObject\DealCandidate;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Zoho Pull Deal',
    description: 'Resolves a Zoho deal by zoho_id or entity_id. If Kanvas already has it, returns it directly; '
        . 'otherwise pulls it from Zoho CRM, links it to its lead and person and downloads its attachments. '
        . 'Inbound one-way; returns the same candidate shape every CRM pull returns.',
    integration: IntegrationsEnum::ZOHO,
)]
class ZohoPullDealActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $entity instanceof Deal
            ? $entity->company
            : Companies::getById($entity->companies_id ?? $params['company_id']);

        $zohoDealId = (string) ($params['zoho_id'] ?? $params['entity_id'] ?? '');

        if ($zohoDealId === '') {
            return $this->failWorkflow(['error' => 'Zoho deal id is required', 'deal' => null]);
        }

        $localDeal = Deal::getByCustomFieldTransactionSafe(
            CustomFieldEnum::ZOHO_DEAL_ID->value,
            $zohoDealId,
            $company,
        ) ?? Deal::getByCustomField(
            CustomFieldEnum::ZOHO_DEAL_ID->value,
            $zohoDealId,
            $company,
        );

        if ($localDeal instanceof Deal) {
            return [new DealCandidate($localDeal)->toArray()];
        }

        // The pull mutation hands us the requesting user; a rule-driven pull has none, so the deal
        // is created as the company owner.
        $user = ($params['user'] ?? null) instanceof UserInterface ? $params['user'] : $company->user;

        $deal = $this->pullDealFromZoho($app, $company, $user, $zohoDealId);

        if ($deal instanceof Deal) {
            return [new DealCandidate($deal)->toArray()];
        }

        return $this->failWorkflow(['error' => 'Failed to pull deal from Zoho', 'deal' => null]);
    }

    protected function pullDealFromZoho(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        string $zohoDealId
    ): ?Deal {
        return new PullZohoDealAction($app, $company, $user, $zohoDealId)->execute();
    }
}
