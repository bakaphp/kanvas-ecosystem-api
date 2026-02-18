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
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;

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

        $lead = $this->message->entity();
        $cacheKey = "unresponded_agent_message:{$lead->getId()}:{$this->channel->getId()}";

        if (! Cache::has($cacheKey)) {
            return;
        }

        $aiMode = $lead->get('ai_mode');
        if ($aiMode !== IntelligenceModeEnum::SUPPORT->value) {
            Cache::forget($cacheKey);

            return;
        }

        Cache::forget($cacheKey);

        try {
            $action = new $this->actionClass(
                $this->channel,
                $this->message,
                $this->agent,
                $this->session
            );

            $action->execute($this->params);
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

                    $note = "The sales agent hasn't responded to the customer's message in 15 minutes. Sally is responding to the customer.";
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
}
