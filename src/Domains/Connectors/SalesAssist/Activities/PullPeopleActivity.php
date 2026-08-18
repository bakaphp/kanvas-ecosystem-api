<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Actions\PullPeopleAction as DriveCentricActionsPullPeopleAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\PullPeopleAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Actions\PullPeopleAction as ReynoldsPullPeopleAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum as ReynoldsConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum as ReynoldsCustomFieldEnum;
use Kanvas\Connectors\Reynolds\Services\XmlParser as ReynoldsXmlParser;
use Kanvas\Connectors\VinSolution\Actions\PullPeopleAction as ActionsPullPeopleAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'SalesAssist Pull Person From CRM',
    description: 'Brings a person\'s contact record INTO Kanvas from whichever CRM this company runs. Inbound; '
        . 'dispatches on the company\'s configured CRM rather than naming one.',
    integration: IntegrationsEnum::SALESASSIST,
)]
class PullPeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?Companies $company = null;
    protected ?Apps $app = null;

    #[Override]
    /**
     * $entity <People>
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $isSync = $entity->id === 0;
        $company = Companies::getById($entity->companies_id);
        $this->company = $company;
        $this->app = $app;
        $peopleId = $params['entity_id'] ?? null;
        $user = $params['user'] ?? null;

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
        $isDriveCentric = $company->get(ConfigurationEnum::STORE_ID->value) !== null;
        $isReynolds = $company->get(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )

        if ($isElead) {
            return new PullPeopleAction($app, $company, $user)->execute($params);
        } elseif ($isVinSolutions) {
            return new ActionsPullPeopleAction(
                $app,
                $company,
                $user
            )->execute(
                email: $params['email'] ?? null,
            );
        } elseif ($isDriveCentric) {
            return (new DriveCentricActionsPullPeopleAction(
                $app,
                $company,
                $user
            ))->execute(
                customerId: $peopleId,
                email: $params['email'] ?? null,
                phone: $params['phone'] ?? null,
            )->toArray();
        } elseif ($isReynolds) {
            // Reynolds is push-only — no GET-customer endpoint exists in the
            // SalesAssist specs. We can only refresh the customer when an
            // inbound Publish Lead Update payload was previously parsed by
            // the webhook job and handed to us via $params['record'] (or the
            // raw envelope via $params['xml']). Otherwise this is a no-op.
            $record = match (true) {
                isset($params['record']) && is_array($params['record']) => $params['record'],
                isset($params['xml']) && is_string($params['xml']) && $params['xml'] !== ''
                    => ReynoldsXmlParser::extractPayloadFromEnvelope($params['xml'])['Record'] ?? null,
                default => null,
            };

            if (is_array($record)) {
                $people = new ReynoldsPullPeopleAction($app, $company, $user)->execute($record);

                return [
                    'id' => $people->getId(),
                    'name_rec_id' => $people->get(ReynoldsCustomFieldEnum::NAME_REC_ID->value),
                ];
            }

            return [
                'message' => 'No Reynolds LDU payload supplied; nothing to pull',
                'people_id' => $entity->id ?: null,
            ];
        }

        return [];
    }
}
