<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ofac\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\ActionEngine\Tasks\Actions\UpdateTaskStatusFromEngagementAction;
use Kanvas\Connectors\Ofac\Actions\OfacClientScreeningAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class OfacScreeningActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::OFAC,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) {
                // Get the lead associated with the message
                $lead = $message->entity();
                if (! $lead || ! $lead instanceof Lead) {
                    throw new Exception('Message must be associated with a lead for OFAC screening');
                }

                $coBuyer = $message->message['people_id'] ?? null;
                if ($coBuyer !== null) {
                    $people = People::fromApp($message->app)->fromCompany($message->company)->where('id', $coBuyer)->first();
                }

                $people = $people ?? $lead->people;

                // Execute OFAC screening action
                $ofacAction = new OfacClientScreeningAction(
                    lead: $lead,
                    message: $message,
                    app: $app,
                    people: $people
                );

                $fileLink = $ofacAction->execute();

                //change checklist status
                $changeStatusAction = new UpdateTaskStatusFromEngagementAction(
                    engagement: $message->engagement,
                    status: 'completed'
                )->execute();

                return [
                    'message' => 'OFAC screening completed successfully',
                    'success' => true,
                    'file_link' => $fileLink,
                    'lead_id' => $lead->getId(),
                    'message_id' => $message->getId(),
                    'people_id' => $people->getId(),
                    'task_engagement_item_id' => count($changeStatusAction) > 0 ? current($changeStatusAction)->getId() : null,
                ];
            },
            company: $message->company,
        );
    }
}
