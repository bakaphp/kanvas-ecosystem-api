<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The conversation responder was a workflow activity a seeded rule ran per company. The inbound
 * pipeline queues the reply itself now, as the agent that owns the number, so the activity is gone:
 * its seeded rules are removed, a rule a person built around it keeps its other steps (one left
 * with nothing is removed too), and the catalog entry goes with it.
 */
return new class () extends Migration {
    private const string ACTIVITY = 'Kanvas\\Communication\\Workflows\\AgentChannelResponderActivity';
    private const string SEEDED_RULE = 'Communication auto-reply';

    public function up(): void
    {
        $workflow = DB::connection('workflow');

        $actionIds = $workflow->table('actions')->where('model_name', self::ACTIVITY)->pluck('id');

        if ($actionIds->isEmpty()) {
            return;
        }

        $stepIds = $workflow->table('rules_workflow_actions')->whereIn('actions_id', $actionIds)->pluck('id');
        $ruleIds = $workflow->table('rules_actions')
            ->whereIn('rules_workflow_actions_id', $stepIds)
            ->where('is_deleted', 0)
            ->pluck('rules_id');

        $workflow->table('rules')
            ->where('name', self::SEEDED_RULE)
            ->whereIn('id', $ruleIds)
            ->update(['is_deleted' => 1]);

        $workflow->table('rules_actions')->whereIn('rules_workflow_actions_id', $stepIds)->update(['is_deleted' => 1]);

        $stillRunning = $workflow->table('rules_actions')
            ->whereIn('rules_id', $ruleIds)
            ->where('is_deleted', 0)
            ->pluck('rules_id');

        $workflow->table('rules')->whereIn('id', $ruleIds->diff($stillRunning))->update(['is_deleted' => 1]);
        $workflow->table('rules_workflow_actions')->whereIn('id', $stepIds)->update(['is_deleted' => 1]);
        $workflow->table('actions')->whereIn('id', $actionIds)->update(['is_deleted' => 1]);
    }

    public function down(): void
    {
        // The activity class no longer exists; there is nothing to restore the rows for.
    }
};
