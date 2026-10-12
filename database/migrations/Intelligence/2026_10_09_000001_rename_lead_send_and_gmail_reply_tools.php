<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendSmsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Gmail\ReplyToEmailTool;

/**
 * send_email → send_lead_email, send_sms → send_lead_sms, reply_to_email → gmail_reply_to_thread.
 *
 * Catalog rows are keyed by handler, so grants survive; only the label and description are refreshed
 * (`sync-tools` without --force never rewrites them). Tenant-written prompts still naming the old ids
 * would make the model call a tool that no longer exists, so the prose columns are rewritten too. Machine
 * config (`config`, `tools_config`, `voice_config`) is left alone: a key there may share the old name
 * and mean something else.
 */
return new class () extends Migration {
    private const array RENAMES = [
        'send_email' => 'send_lead_email',
        'send_sms' => 'send_lead_sms',
        'reply_to_email' => 'gmail_reply_to_thread',
    ];

    private const array CATALOG_LABELS = [
        SendEmailTool::class => ['Send Email', 'Send Email To Lead'],
        SendSmsTool::class => ['Send SMS', 'Send SMS To Lead'],
        ReplyToEmailTool::class => ['Reply To Email', 'Gmail Reply To Thread'],
    ];

    private const array PROSE_COLUMNS = [
        'agents' => ['description', 'role', 'soul', 'instructions', 'tool_usage', 'output_format', 'identity', 'user_context'],
        'agent_types' => ['description', 'role', 'soul', 'instructions', 'output_format'],
    ];

    public function up(): void
    {
        foreach (self::CATALOG_LABELS as $handler => [, $label]) {
            $this->updateCatalog($handler, $label, new ReflectionClass($handler)->getDefaultProperties()['description']);
        }

        $this->rewriteProse(self::RENAMES);
    }

    public function down(): void
    {
        foreach (self::CATALOG_LABELS as $handler => [$label]) {
            $this->updateCatalog($handler, $label, null);
        }

        $this->rewriteProse(array_flip(self::RENAMES));
    }

    private function updateCatalog(string $handler, string $label, ?string $description): void
    {
        $tools = DB::connection('intelligence')->table('nervous_system_tools');

        // `handler` is not indexed: resolve the ids first and write by primary key.
        $ids = (clone $tools)->where('apps_id', 0)->where('handler', $handler)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        $tools->whereIn('id', $ids)->update(array_filter(
            ['name' => $label, 'description' => $description],
            fn (?string $value): bool => $value !== null,
        ));
    }

    /**
     * @param array<string, string> $renames
     */
    private function rewriteProse(array $renames): void
    {
        $patterns = array_map(fn (string $from): string => '/\b' . preg_quote($from, '/') . '\b/', array_keys($renames));

        foreach (self::PROSE_COLUMNS as $table => $columns) {
            DB::connection('intelligence')
                ->table($table)
                ->select(['id', ...$columns])
                ->where(function (Builder $query) use ($columns, $renames): void {
                    foreach ($columns as $column) {
                        foreach (array_keys($renames) as $from) {
                            $query->orWhere($column, 'like', '%' . $from . '%');
                        }
                    }
                })
                ->chunkById(200, function (Collection $rows) use ($table, $columns, $patterns, $renames): void {
                    foreach ($rows as $row) {
                        $changes = [];

                        foreach ($columns as $column) {
                            if ($row->{$column} === null) {
                                continue;
                            }

                            $rewritten = preg_replace($patterns, array_values($renames), (string) $row->{$column});
                            // preg_replace answers null on a PCRE failure; writing that back would wipe the prompt.
                            if ($rewritten !== null && $rewritten !== $row->{$column}) {
                                $changes[$column] = $rewritten;
                            }
                        }

                        if ($changes !== []) {
                            DB::connection('intelligence')->table($table)->where('id', $row->id)->update($changes);
                        }
                    }
                });
        }
    }
};
