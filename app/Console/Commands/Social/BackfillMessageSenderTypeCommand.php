<?php

declare(strict_types=1);

namespace App\Console\Commands\Social;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;

/**
 * Backfill messages.sender_type from the JSON `message` payload (from_me / from_ia /
 * from_orchestrator). New rows are populated live by MessageObserver::saving(); this
 * catches up history so the Engage usage dashboard's human-vs-AI split covers old data.
 *
 * Cursors by id (not by "sender_type IS NULL") so non-communication rows — which stay
 * NULL by design — don't get reselected forever. Safe to re-run and to resume with
 * --from-id after an interruption. Writes via the query builder (no model events), so it
 * won't trigger observers or touch updated_at.
 */
class BackfillMessageSenderTypeCommand extends Command
{
    protected $signature = 'kanvas:social:backfill-message-sender-type
        {--chunk=2000 : Rows scanned per batch}
        {--app= : Restrict to a single apps_id}
        {--from-id=0 : Resume from this message id (exclusive)}
        {--dry-run : Classify and report without writing}';

    protected $description = 'Backfill messages.sender_type from the JSON message payload.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $appId = $this->option('app') !== null ? (int) $this->option('app') : null;
        $dryRun = (bool) $this->option('dry-run');
        $lastId = (int) $this->option('from-id');

        $connection = DB::connection('social');

        $scanned = 0;
        $updated = 0;
        $counts = [
            MessageSenderTypeEnum::USER->value => 0,
            MessageSenderTypeEnum::AGENT->value => 0,
            MessageSenderTypeEnum::CONTACT->value => 0,
            'null' => 0,
        ];

        $this->info(sprintf(
            'Backfilling messages.sender_type%s (chunk=%d, from-id=%d)%s',
            $appId !== null ? " for app {$appId}" : '',
            $chunk,
            $lastId,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        do {
            $rows = $connection->table('messages')
                ->select(['id', 'message'])
                ->when($appId !== null, fn ($q) => $q->where('apps_id', $appId))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $buckets = [];
            foreach ($rows as $row) {
                $type = MessageSenderTypeEnum::fromPayload($this->decodePayload($row->message));
                $counts[$type?->value ?? 'null']++;

                if ($type !== null) {
                    $buckets[$type->value][] = (int) $row->id;
                }
            }

            if (! $dryRun) {
                foreach ($buckets as $type => $ids) {
                    $updated += $connection->table('messages')
                        ->whereIn('id', $ids)
                        ->update(['sender_type' => $type]);
                }
            } else {
                $updated += array_sum(array_map('count', $buckets));
            }

            $scanned += $rows->count();
            $lastId = (int) $rows->last()->id;

            $this->line(sprintf('  scanned %d (last id %d), updated %d', $scanned, $lastId, $updated));
        } while ($rows->count() === $chunk);

        $this->newLine();
        $this->info(sprintf(
            'Done. Scanned %d rows. %s %d: user=%d agent=%d contact=%d (non-comm/null=%d).',
            $scanned,
            $dryRun ? 'Would update' : 'Updated',
            $updated,
            $counts[MessageSenderTypeEnum::USER->value],
            $counts[MessageSenderTypeEnum::AGENT->value],
            $counts[MessageSenderTypeEnum::CONTACT->value],
            $counts['null'],
        ));

        return self::SUCCESS;
    }

    /**
     * Decode the raw `message` column the same way Message::getMessage() does, including the
     * double-encoded-string legacy shape, so classification matches runtime exactly.
     *
     * @return array<array-key, mixed>
     */
    private function decodePayload(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            $raw = substr(stripslashes($raw), 1, -1);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
