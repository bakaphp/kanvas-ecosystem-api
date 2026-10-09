<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Enums\ConversationMessageKindEnum;

/**
 * Stamps `kind` on the rows written before the column existed, reading what `meta` says about them.
 * Idempotent: only rows with no kind are touched, so a re-run finishes what a killed run left. Run
 * it directly, not through Composer; the first pass reads every row of the table once.
 */
class BackfillConversationKindCommand extends Command
{
    protected $signature = 'agents:backfill-conversation-kind {--chunk=1000 : Rows stamped per statement}';

    protected $description = 'Stamp kind (summary / tool_call / tool_call_result) on agent_conversation_messages rows that predate the column';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $this->table(
            ['Kind', 'Rows'],
            array_map(
                fn (ConversationMessageKindEnum $kind): array => [$kind->value, $this->stamp($kind, $chunk)],
                ConversationMessageKindEnum::cases(),
            ),
        );

        return self::SUCCESS;
    }

    private function stamp(ConversationMessageKindEnum $kind, int $chunk): int
    {
        $stamped = 0;

        $this->candidates($kind)
            ->select('id')
            ->chunkById($chunk, function (Collection $rows) use ($kind, &$stamped): void {
                $stamped += $this->messages()
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->update(['kind' => $kind->value]);
            }, 'id');

        return $stamped;
    }

    private function candidates(ConversationMessageKindEnum $kind): Builder
    {
        $query = $this->messages()->whereNull('kind');

        return $kind === ConversationMessageKindEnum::SUMMARY
            ? $query->whereRaw("JSON_EXTRACT(meta, '$.\"__meta\".summary') = CAST('true' AS JSON)")
            : $query->where('meta->type', $kind->value);
    }

    private function messages(): Builder
    {
        return DB::connection('intelligence')->table('agent_conversation_messages');
    }
}
