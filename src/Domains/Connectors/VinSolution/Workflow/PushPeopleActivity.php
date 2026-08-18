<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushPeopleAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Services\ContactRejectionService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'VinSolution Push Person',
    description: 'Pushes a person\'s contact record into VinSolutions. Outbound one-way write; use the '
        . 'push-lead step for the opportunity itself.',
    integration: IntegrationsEnum::VIN_SOLUTION,
)]
class PushPeopleActivity extends KanvasActivity
{
    public function execute(People $people, Apps $app, array $params): array
    {
        $company = $people->company;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            additionalParams: $params,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams): array {
                try {
                    $pushPeopleAction = new PushPeopleAction(
                        people: $people,
                    );

                    $results = $pushPeopleAction->execute();
                } catch (ClientException $e) {
                    if ($e->getResponse()?->getStatusCode() === 404) {
                        return $this->failWorkflow([
                            'error' => 'VinSolution assigned user not found',
                            'people_id' => $people->getId(),
                            'company_id' => $people->companies_id,
                        ]);
                    }

                    if (! ContactRejectionService::isRecordRejection($e)) {
                        throw $e;
                    }

                    return $this->failWorkflow([
                        'error' => 'VinSolution rejected the contact information',
                        'reason' => ContactRejectionService::recordForPeople($people, $e),
                        'people_id' => $people->getId(),
                        'company_id' => $people->companies_id,
                    ]);
                } catch (ServerException $e) {
                    return $this->failWorkflow([
                        'error' => 'VinSolution server error on contact push',
                        'status' => $e->getResponse()?->getStatusCode(),
                        'response' => (string) $e->getResponse()?->getBody(),
                        'people_id' => $people->getId(),
                        'company_id' => $people->companies_id,
                    ]);
                }

                return [
                    'message' => 'VinSolution integration completed successfully',
                    'people' => $results,
                ];
            },
            company: $company,
        );
    }
}
