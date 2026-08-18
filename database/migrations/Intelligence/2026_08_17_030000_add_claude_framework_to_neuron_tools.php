<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tag every existing `neuron` tool as `claude` too.
 *
 * A NeuronAI tool is runnable by a Claude Managed Agent — the custom-tool bridge hands the call back
 * in-process and executes the same object. But `CapabilityProvider::getActiveTools()` and the grant
 * UI both filter by the agent type's provider, so until a tool carries the `claude` tag a hosted
 * agent cannot be granted it at all.
 *
 * Deliberately a targeted backfill rather than `sync-tools --force`: force re-writes name,
 * description, category and version from the attribute, which would clobber any curated edits.
 * This touches only the frameworks array. New tools pick the tag up at discovery time.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        $this->rewriteFrameworks(
            static function (array $frameworks): ?array {
                if (! in_array('neuron', $frameworks, true) || in_array('claude', $frameworks, true)) {
                    return null;
                }

                $frameworks[] = 'claude';

                return $frameworks;
            }
        );
    }

    public function down(): void
    {
        $this->rewriteFrameworks(
            static function (array $frameworks): ?array {
                if (! in_array('claude', $frameworks, true)) {
                    return null;
                }

                return array_values(array_filter($frameworks, static fn (string $f): bool => $f !== 'claude'));
            }
        );
    }

    /**
     * The column is a JSON array cast by the model, so it is read and rewritten row by row rather
     * than with a JSON function — MariaDB stores it as longtext and its JSON support differs enough
     * from MySQL's that a server-side JSON_ARRAY_APPEND is not portable here.
     *
     * @param callable(list<string>): (list<string>|null) $mutate Returns null to leave the row alone.
     */
    private function rewriteFrameworks(callable $mutate): void
    {
        DB::connection('intelligence')
            ->table('nervous_system_tools')
            ->select(['id', 'frameworks'])
            ->orderBy('id')
            ->chunk(500, function ($tools) use ($mutate): void {
                foreach ($tools as $tool) {
                    $decoded = json_decode((string) $tool->frameworks, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    $frameworks = array_values(array_map('strval', $decoded));
                    $updated = $mutate($frameworks);

                    if ($updated === null) {
                        continue;
                    }

                    DB::connection('intelligence')
                        ->table('nervous_system_tools')
                        ->where('id', $tool->id)
                        ->update(['frameworks' => json_encode($updated)]);
                }
            });
    }
};
