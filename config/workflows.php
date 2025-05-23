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

    'monitor' => env('WORKFLOW_MONITOR', false),

    'monitor_url' => env('WORKFLOW_MONITOR_URL'),

    'monitor_api_key' => env('WORKFLOW_MONITOR_API_KEY'),

    'monitor_connection' => env('WORKFLOW_MONITOR_CONNECTION', config('queue.default')),

    'monitor_queue' => env(
        'WORKFLOW_MONITOR_QUEUE',
        config('queue.connections.' . config('queue.default') . '.queue', 'default')
    ),
];
