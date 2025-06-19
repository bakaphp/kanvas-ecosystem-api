<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\AddNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class LeadNotesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $company = $message->company;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        $lead = $message->entity();

        if (! $lead) {
            throw new Exception('Lead not found');
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($lead) {
                $leadNoteAction = new AddNoteToLeadAction(
                    lead: $lead,
                    message: $message,
                );

                return $leadNoteAction->execute();
            },
            company: $company,
        );
    }
}
