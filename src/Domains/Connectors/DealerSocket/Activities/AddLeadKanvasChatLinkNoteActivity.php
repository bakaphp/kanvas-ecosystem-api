<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Activities;

use Baka\Support\Url;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Add Kanvas Chat Link Note To DealerSocket',
    description: 'Writes a note on the DealerSocket lead containing a deep link back to the Kanvas '
        . 'conversation, so CRM users can click through to the full thread.',
    integration: IntegrationsEnum::DEALERSOCKET,
)]
class AddLeadKanvasChatLinkNoteActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::DEALERSOCKET,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, Apps $app, mixed $integrationCompany, array $additionalParams): array {
                $chatLink = SessionChannelService::generateChannelLink($lead, $app);

                if ($chatLink === null) {
                    return $this->failWorkflow([
                        'error' => 'Unable to generate Kanvas chat link for this lead',
                        'lead' => $lead->getId(),
                    ]);
                }

                $shortUrl = Url::getShortUrl($chatLink, $app);
                $note = $app->name . ' Engage link created. Use it to message this lead: ' . $shortUrl;

                $response = new DealerSocketWorkNoteService(
                    $lead->app,
                    $lead->company,
                )->addNoteToLead($lead, $note);

                return [
                    'note' => $response,
                    'link' => $shortUrl,
                    'lead' => $lead->getId(),
                ];
            },
            company: $lead->company,
        );
    }
}
