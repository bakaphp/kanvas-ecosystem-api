<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
        protected ?string $token = null,
    ) {
    }

    /**
     * The token every dispatch on this channel overwrites. Whoever still matches when the delay
     * expires is the one that answers.
     */
    public static function cacheKey(int $channelId): string
    {
        return 'agent-delayed-reply:' . $channelId;
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        if ($this->isStale()) {
            return;
        }

        // Serialised per channel because `is_un_response` is only set once the winning turn's model
        // call has returned. Two jobs that reached the guard together would both pass it and both
        // reply; holding the lock means the loser re-reads a message that is already answered.
        try {
            Cache::lock('agent-delayed-reply-turn:' . $this->channel->getId(), 300)
                ->block(10, fn () => $this->respond());
        } catch (LockTimeoutException) {
            // Another turn on this channel is still running. It is answering the same customer, so
            // dropping this one is the intended outcome, not a fault.
            return;
        }
    }

    /**
     * A later inbound message on this channel re-armed the token, so this turn is answering a
     * conversation that has already moved on.
     */
    private function isStale(): bool
    {
        if ($this->token === null) {
            return false;
        }

        $current = Cache::get(self::cacheKey($this->channel->getId()));

        if ($current !== $this->token) {
            return true;
        }

        // Spent before the turn runs: `$tries` is not 1 here and a throw after the reply has
        // shipped would otherwise re-enter with the token still valid and send it twice.
        Cache::forget(self::cacheKey($this->channel->getId()));

        return false;
    }

    private function respond(): void
    {
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
                    $unrespondedMessageMinutes = $entity->company->get(ConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value)
                        ?? ConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES_DEFAULT;
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
