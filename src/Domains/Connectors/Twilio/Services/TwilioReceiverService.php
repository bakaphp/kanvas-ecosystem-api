<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Services;

use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Services\WorkflowActionCatalog;

/**
 * One inbound SMS receiver per company, shared by all of its numbers.
 */
class TwilioReceiverService
{
    public function __construct(protected WorkflowActionCatalog $catalog = new WorkflowActionCatalog())
    {
    }

    public function find(Agent $agent): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->fromApp($agent->app)
            ->fromCompany($agent->company)
            ->forJob(ProcessTwilioWebhookJob::class)
            ->where('is_active', 1)
            ->notDeleted()
            ->orderBy('id')
            ->first();
    }

    /**
     * A receiver that was switched off stays off; the agent gets a new one instead.
     */
    public function ensure(Agent $agent): ReceiverWebhook
    {
        $receiver = $this->find($agent);
        if ($receiver !== null) {
            return $receiver;
        }

        $app = $agent->app;
        $company = $agent->company;
        $action = $this->catalog->register(ProcessTwilioWebhookJob::class);
        $userId = $agent->user->getId();

        return ReceiverWebhook::query()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'action_id' => $action->getId(),
            // ProcessWebhookJob logs every inbound call in as this user.
            'users_id' => $userId,
            'name' => $action->name,
            'description' => 'Created when an agent was given an SMS number.',
            // Matches the shape of existing receivers. Nothing reads these keys yet: the workflow
            // rule's agent_id decides who answers, and it is the receiver's users_id column that is used.
            'configuration' => [
                'user_id' => $userId,
                'agent_id' => $agent->getId(),
                'users_id' => $userId,
            ],
            'is_active' => true,
        ]);
    }
}
