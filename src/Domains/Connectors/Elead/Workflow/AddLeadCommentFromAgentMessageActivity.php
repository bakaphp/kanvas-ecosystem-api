<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class AddLeadCommentFromAgentMessageActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $message->company;

        if (! $company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    throw new Exception('Lead not found');
                }

                //$syncLeadAction = new SyncLeadAction($lead);
                //$eLeadOpportunity = $syncLeadAction->execute();
                $eLeadOpportunity = EntitiesLead::getById($app, $lead->company, (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value));
                $note = $message->message['content'] ?? '';

                if (empty($note)) {
                    return [
                        'error' => 'Message content is empty, no note to add',
                    ];
                }

                $fromAgent = (bool) ($message->message['from_me'] ?? false);
                $note = ($fromAgent ? 'Sally: ' : 'Customer: ') . $note;
                $eLeadOpportunity->addComment($note);

                return [
                    'note' => $note,
                    'from_agent' => $fromAgent,
                    'lead' => $lead->getId(),
                ];
            },
            company: $company,
        );
    }
}
