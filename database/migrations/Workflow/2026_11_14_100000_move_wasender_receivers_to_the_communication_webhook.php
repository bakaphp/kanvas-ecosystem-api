<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Workflow\Services\WorkflowActionCatalog;

/**
 * WaSender is a Communication provider and its own webhook job is gone. A session keeps posting to
 * the URL it was given, so each receiver is moved onto the communication webhook in place: same
 * row, same URL, same secret and the same group and assistant settings in its configuration. The
 * number becomes a connection when setup next runs (`communication:setup`, after
 * `kanvas:wasender:adopt-communication` for a number linked without the integration form).
 */
return new class () extends Migration {
    private const string LEGACY_JOB = 'Kanvas\\Connectors\\WaSender\\Webhooks\\ProcessWaSenderWebhookJob';
    private const string PROVIDER = 'wasender';

    public function up(): void
    {
        $workflow = DB::connection('workflow');
        $legacyActionIds = $workflow->table('actions')->where('model_name', self::LEGACY_JOB)->pluck('id');

        if ($legacyActionIds->isEmpty()) {
            return;
        }

        $receivers = $workflow->table('receiver_webhooks')->whereIn('action_id', $legacyActionIds)->get();

        if ($receivers->isNotEmpty()) {
            $actionId = new WorkflowActionCatalog()->register(ProcessCommunicationWebhookJob::class)->getId();

            foreach ($receivers as $receiver) {
                $configuration = json_decode((string) $receiver->configuration, true);

                // A column written through a string cast holds JSON inside a JSON string.
                if (is_string($configuration)) {
                    $configuration = json_decode($configuration, true);
                }

                $configuration = is_array($configuration) ? $configuration : [];
                // Not `managed`: Communication did not create this receiver and never switches it off.
                $configuration['communication'] = ['provider' => self::PROVIDER];

                $workflow->table('receiver_webhooks')->where('id', $receiver->id)->update([
                    'action_id' => $actionId,
                    'configuration' => json_encode($configuration),
                ]);
            }
        }

        $workflow->table('actions')->whereIn('id', $legacyActionIds)->update(['is_deleted' => 1]);
    }

    public function down(): void
    {
        // The legacy job class no longer exists; there is nothing to point the receivers back at.
    }
};
