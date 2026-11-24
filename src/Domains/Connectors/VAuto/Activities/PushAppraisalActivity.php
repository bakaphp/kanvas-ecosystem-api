<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VAuto\Activities;

use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VAuto\Actions\PushAppraisalAction;
use Kanvas\Connectors\VAuto\Client;
use Kanvas\Connectors\VAuto\Enums\ConfigurationEnum;
use Kanvas\Connectors\VAuto\Exceptions\TradeInNotAppraisableException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'vAuto Push Appraisal',
    description: 'Opens a trade-in appraisal in vAuto when a customer submits a trade walk or add-trade '
        . 'form on a lead, and stores the appraisal id and deep link on the message and the lead. '
        . 'Runs alongside whichever dealer CRM the company uses; it only talks to vAuto.',
    integration: IntegrationsEnum::VAUTO,
    requiresConfig: [
        ConfigurationEnum::ACCESS_ID,
        ConfigurationEnum::SECRET_KEY,
        ConfigurationEnum::ENTITY_ID,
    ],
)]
class PushAppraisalActivity extends KanvasActivity
{
    public $tries = 3;

    protected const array TRADE_IN_VERBS = [
        ActionEnum::TRADE_WALK,
        ActionEnum::ADD_TRADE,
    ];

    public function execute(Message $message, Apps $app, array $params): array
    {
        $company = $message->company;

        if (! Client::isConfigured($company)) {
            return [
                'message_id' => $message->getId(),
                'message' => 'vAuto is not configured for this company, skipping',
            ];
        }

        $lead = $message->entity(Lead::class);

        if (! $lead instanceof Lead) {
            return $this->failWorkflow([
                'message_id' => $message->getId(),
                'error' => 'Message is not associated with a Lead',
            ]);
        }

        $messageData = $message->getMessage();
        $verb = ActionEnum::tryFrom((string) ($messageData['verb'] ?? ''));
        $status = (string) ($messageData['status'] ?? '');

        if (! in_array($verb, self::TRADE_IN_VERBS, true) || $status !== ActionStatusEnum::SUBMITTED->value) {
            return [
                'message_id' => $message->getId(),
                'message' => 'Not a submitted trade-in, skipping',
                'verb' => $verb?->value,
                'status' => $status,
            ];
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::VAUTO,
            additionalParams: $params,
            integrationOperation: function ($message) use ($lead) {
                try {
                    return new PushAppraisalAction($lead, $message)->execute();
                } catch (TradeInNotAppraisableException $e) {
                    return $this->failWorkflow([
                        'message_id' => $message->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            },
            company: $company,
        );
    }
}
