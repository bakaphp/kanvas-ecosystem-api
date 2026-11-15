<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Kanvas\Communication\Messages\Activities\RespondToInboundMessageActivity;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Activities\DeliverHumanReplyActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Activities\ReachOutToNewLeadActivity;
use Kanvas\Intelligence\Agents\Activities\RespondOnAiAssistChannelActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Activities\IndexAgentDocumentsActivity;
use Kanvas\Intelligence\Knowledge\Activities\PruneDeletedFileKnowledgeActivity;
use Kanvas\Intelligence\Triggers\Activities\ApplyLeadAiModeActivity;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Models\StoredWorkflowException;
use Kanvas\Workflow\Models\StoredWorkflowLog;
use Kanvas\Workflow\Models\StoredWorkflowSignal;
use Kanvas\Workflow\Models\StoredWorkflowTimer;

return [
    'workflows_folder' => 'Workflows',

    'base_model' => Model::class,

    'stored_workflow_model' => StoredWorkflow::class,

    'stored_workflow_exception_model' => StoredWorkflowException::class,

    'stored_workflow_log_model' => StoredWorkflowLog::class,

    'stored_workflow_signal_model' => StoredWorkflowSignal::class,

    'stored_workflow_timer_model' => StoredWorkflowTimer::class,

    'workflow_relationships_table' => 'workflow_relationships',

    'prune_age' => '1 month',

    /*
     * Integration history (`entity_integration_history`) kept, in days, by
     * `kanvas:workflow-prune-integration-history`. A skipped run says why nothing happened and
     * is only useful while someone is still asking; everything else is kept longer.
     */
    'integration_history_retention_days' => (int) env('WORKFLOW_HISTORY_RETENTION_DAYS', 60),
    'skipped_history_retention_days' => (int) env('WORKFLOW_SKIPPED_HISTORY_RETENTION_DAYS', 7),

    /*
     * Rules installed for every company of every app (EnsureAppDefaultRulesAction): what a company
     * does on its own, as a trigger. The activity decides whether anything happens. Each entry:
     * `trigger` (a WorkflowEnum value), `model`, `activity`, `name`, `async` (default true), and
     * `conditions` (WireRuleAction's shape, all of them must hold) to filter on plain values the
     * event carries. A company's own rule running the same activity replaces the app default.
     */
    'app_default_rules' => [
        [
            'trigger' => WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
            'model' => Channel::class,
            'activity' => RespondToInboundMessageActivity::class,
            'name' => 'Communication: answer inbound messages',
        ],
        [
            'trigger' => WorkflowEnum::UPDATED->value,
            'model' => Channel::class,
            'activity' => DeliverHumanReplyActivity::class,
            'name' => 'Leads: deliver a person\'s reply to the customer',
            'conditions' => [
                ['attribute_name' => 'from_human', 'operator' => '==', 'value' => 'true'],
            ],
        ],
        [
            'trigger' => WorkflowEnum::UPDATED->value,
            'model' => Channel::class,
            'activity' => RespondOnAiAssistChannelActivity::class,
            'name' => 'Agents: answer on the AI Assist channel',
            'conditions' => [
                ['attribute_name' => 'from_human', 'operator' => '==', 'value' => 'true'],
                [
                    'attribute_name' => 'name',
                    'operator' => '==',
                    'value' => ChannelNameEnum::AI_ASSIST->value,
                ],
            ],
        ],
        [
            'trigger' => WorkflowEnum::CREATED->value,
            'model' => Lead::class,
            'activity' => ReachOutToNewLeadActivity::class,
            'name' => 'Agents: reach out to new leads',
        ],
        [
            'trigger' => WorkflowEnum::TRIGGER_AI->value,
            'model' => Lead::class,
            'activity' => ApplyLeadAiModeActivity::class,
            'name' => 'Agents: apply a lead\'s AI mode',
        ],
        [
            'trigger' => WorkflowEnum::ATTACH_FILE->value,
            'model' => Agent::class,
            'activity' => IndexAgentDocumentsActivity::class,
            'name' => 'Knowledge: index an agent\'s documents',
        ],
        [
            'trigger' => WorkflowEnum::DELETED->value,
            'model' => Filesystem::class,
            'activity' => PruneDeletedFileKnowledgeActivity::class,
            'name' => 'Knowledge: prune a deleted file',
        ],
    ],

    'monitor' => env('WORKFLOW_MONITOR', false),

    'monitor_url' => env('WORKFLOW_MONITOR_URL'),

    'monitor_api_key' => env('WORKFLOW_MONITOR_API_KEY'),

    'monitor_connection' => env('WORKFLOW_MONITOR_CONNECTION', config('queue.default')),

    'monitor_queue' => env(
        'WORKFLOW_MONITOR_QUEUE',
        config('queue.connections.' . config('queue.default') . '.queue', 'default')
    ),
];
