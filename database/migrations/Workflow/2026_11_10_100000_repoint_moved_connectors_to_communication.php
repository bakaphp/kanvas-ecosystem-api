<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Twilio and Mailgun moved into Communication\Channels\{Medium}\Providers\{Vendor}. Receivers and
 * rules reach their class through `actions.model_name`, and integrations through
 * `integrations.handler`; left on the old name, `kanvas:workflow-sync-actions` would add a second
 * action per class under the new one while every receiver and rule stayed on the first.
 *
 * The old classes remain as subclasses of the new ones for what this cannot reach: payloads already
 * queued in Redis and running workflows' stored state.
 */
return new class () extends Migration {
    // Exact classes first: a class that left its connector for the shared Communication workflows is
    // not under the connector's new folder.
    private const array MOVES = [
        'Kanvas\\Connectors\\Twilio\\Workflows\\HumanAgentChannelResponseActivity' => 'Kanvas\\Communication\\Workflows\\HumanAgentChannelResponseActivity',
        'Kanvas\\Connectors\\Twilio\\' => 'Kanvas\\Communication\\Channels\\Sms\\Providers\\Twilio\\',
        'Kanvas\\Connectors\\Mailgun\\' => 'Kanvas\\Communication\\Channels\\Email\\Providers\\Mailgun\\',
    ];

    public function up(): void
    {
        foreach (self::MOVES as $old => $new) {
            $this->repoint($old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $old => $new) {
            $this->repoint($new, $old);
        }
    }

    /**
     * A row whose target name already exists (a sync that ran first) is left alone: the subclass
     * keeps it working, and rewriting it would hand two rows the same class.
     */
    private function repoint(string $from, string $to): void
    {
        foreach (['actions' => 'model_name', 'integrations' => 'handler'] as $table => $column) {
            $rows = DB::connection('workflow')
                ->table($table)
                ->where($column, 'like', str_replace('\\', '\\\\', $from) . '%')
                ->get(['id', $column]);

            foreach ($rows as $row) {
                $target = $to . substr($row->{$column}, strlen($from));

                $taken = DB::connection('workflow')->table($table)->where($column, $target)->exists();

                if (! $taken) {
                    DB::connection('workflow')->table($table)->where('id', $row->id)->update([$column => $target]);
                }
            }
        }
    }
};
