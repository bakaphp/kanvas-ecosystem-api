<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A person's reply in a lead conversation was sent by a rule per company, carrying the number to
 * send from. Guild delivers it itself now, on the company's connection for that medium, so the
 * activity is gone: its step leaves every rule, a rule left with nothing is removed, and the catalog
 * entry goes with it. Run before the workers restart, or a reply is sent twice.
 */
return new class () extends Migration {
    private const string ACTIVITY = 'Kanvas\\Connectors\\Twilio\\Workflows\\HumanAgentChannelResponseActivity';

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
