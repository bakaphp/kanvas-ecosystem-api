<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ofac\Activities;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\Ofac\Actions\OfacClientScreeningAction;
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

        // Get the lead associated with the message
        $lead = $message->entity();
        if (! $lead || ! $lead instanceof Lead) {
            throw new Exception('Message must be associated with a lead for OFAC screening');
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::OFAC,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($lead) {
                // Execute OFAC screening action
                $ofacAction = new OfacClientScreeningAction(
                    lead: $lead,
                    message: $message,
                    app: $app
                );

                $fileLink = $ofacAction->execute();

                return [
                    'message' => 'OFAC screening completed successfully',
                    'success' => true,
                    'file_link' => $fileLink,
                    'lead_id' => $lead->getId(),
                    'message_id' => $message->getId(),
                ];
            },
            company: $message->company,
        );
    }
}
