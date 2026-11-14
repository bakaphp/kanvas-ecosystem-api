<?php

declare(strict_types=1);

return [
    'workflows_folder' => 'Workflows',

    'base_model' => Illuminate\Database\Eloquent\Model::class,

    'stored_workflow_model' => Kanvas\Workflow\Models\StoredWorkflow::class,

    'stored_workflow_exception_model' => Kanvas\Workflow\Models\StoredWorkflowException::class,

    'stored_workflow_log_model' => Kanvas\Workflow\Models\StoredWorkflowLog::class,

    'stored_workflow_signal_model' => Kanvas\Workflow\Models\StoredWorkflowSignal::class,

    'stored_workflow_timer_model' => Kanvas\Workflow\Models\StoredWorkflowTimer::class,

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
            'trigger' => Kanvas\Workflow\Enums\WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
            'model' => Kanvas\Social\Channels\Models\Channel::class,
            'activity' => Kanvas\Communication\Messages\Activities\RespondToInboundMessageActivity::class,
            'name' => 'Communication: answer inbound messages',
        ],
        [
            'trigger' => Kanvas\Workflow\Enums\WorkflowEnum::UPDATED->value,
            'model' => Kanvas\Social\Channels\Models\Channel::class,
            'activity' => Kanvas\Guild\Leads\Activities\DeliverHumanReplyActivity::class,
            'name' => 'Leads: deliver a person\'s reply to the customer',
            'conditions' => [
                ['attribute_name' => 'from_human', 'operator' => '==', 'value' => 'true'],
            ],
        ],
        [
            'trigger' => Kanvas\Workflow\Enums\WorkflowEnum::UPDATED->value,
            'model' => Kanvas\Social\Channels\Models\Channel::class,
            'activity' => Kanvas\Intelligence\Agents\Activities\RespondOnAiAssistChannelActivity::class,
            'name' => 'Agents: answer on the AI Assist channel',
            'conditions' => [
                ['attribute_name' => 'from_human', 'operator' => '==', 'value' => 'true'],
                [
                    'attribute_name' => 'name',
                    'operator' => '==',
                    'value' => Kanvas\Social\Channels\Enums\ChannelNameEnum::AI_ASSIST->value,
                ],
            ],
        ],
        [
            'trigger' => Kanvas\Workflow\Enums\WorkflowEnum::CREATED->value,
            'model' => Kanvas\Guild\Leads\Models\Lead::class,
            'activity' => Kanvas\Intelligence\Agents\Activities\ReachOutToNewLeadActivity::class,
            'name' => 'Agents: reach out to new leads',
        ],
        [
            'trigger' => Kanvas\Workflow\Enums\WorkflowEnum::TRIGGER_AI->value,
            'model' => Kanvas\Guild\Leads\Models\Lead::class,
            'activity' => Kanvas\Intelligence\Triggers\Activities\ApplyLeadAiModeActivity::class,
            'name' => 'Agents: apply a lead\'s AI mode',
        ],
        [
            'trigger' => Kanvas\Workflow\Enums\WorkflowEnum::ATTACH_FILE->value,
            'model' => Kanvas\Intelligence\Agents\Models\Agent::class,
            'activity' => Kanvas\Intelligence\Knowledge\Activities\IndexAgentDocumentsActivity::class,
            'name' => 'Knowledge: index an agent\'s documents',
        ],
        [
            'trigger' => Kanvas\Workflow\Enums\WorkflowEnum::DELETED->value,
            'model' => Kanvas\Filesystem\Models\Filesystem::class,
            'activity' => Kanvas\Intelligence\Knowledge\Activities\PruneDeletedFileKnowledgeActivity::class,
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
