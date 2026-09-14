<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;

class SendUnrespondedAgentMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Channel $channel,
        protected Message $message,
        protected Agent $agent,
        protected Apps $app,
        protected array $params = [],
        protected string $actionClass = '',
        protected ?Session $session = null,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $this->message->refresh();

        if ($this->message->is_un_response) {
            return;
        }

        $lead = $this->message->entity();

        if (! $lead instanceof Lead || ! $lead->isAiSupport()) {
            return;
        }

        try {
            $action = new $this->actionClass(
                $this->channel,
                $this->message,
                $this->agent,
                $this->session
            );

            $actionResult = $action->execute($this->params);
            $this->updateIntegrationHistoryResult($actionResult);
            $entity = $this->message->entity();
            if ($entity instanceof Lead) {
                $entity->fireWorkflow(
                    WorkflowEnum::TRIGGER_AI->value,
                    true,
                    [
                        'trigger_type' => TriggersEnum::AI_TAKEOVER->value,
                    ]
                );

                DailyReportService::track(
                    $entity->app,
                    $entity->company,
                    'ai_unresponde_message_sent'
                );

                try {
                    $isElead = $entity->company->get(CustomFieldEnum::COMPANY->value) !== null;
                    $isVinSolutions = $entity->company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
                    $unrespondedMessageMinutes = $entity->company->get(ConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value) ?? 15;
                    $note = "The sales agent hasn't responded to the customer's message in {$unrespondedMessageMinutes} minutes. Sally is responding to the customer.";
                    if ($isElead) {
                        $eLeadOpportunity = EntitiesLead::getById(
                            $lead->app,
                            $lead->company,
                            (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                        );
                        $eLeadOpportunity->addComment($note);
                    } elseif ($isVinSolutions) {
                        new PushNoteToLeadAction(
                            lead: $entity,
                            message: $this->message,
                        )->execute($note);
                    }

                    $entity->set('sended_note_un_response', true);
                } catch (ClientException $e) {
                } catch (Exception $e) {
                }
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function updateIntegrationHistoryResult(array $actionResult): void
    {
        $history = EntityIntegrationHistory::where('entity_namespace', Channel::class)
            ->where('entity_id', $this->channel->getId())
            ->where('apps_id', $this->app->getId())
            ->latest()
            ->first();

        if ($history === null) {
            return;
        }

        $history->response = array_merge(
            is_array($history->response) ? $history->response : [],
            ['delayed_action_result' => $actionResult]
        );
        $history->saveOrFail();
    }
}
