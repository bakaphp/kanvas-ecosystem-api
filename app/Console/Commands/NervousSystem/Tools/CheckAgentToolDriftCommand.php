<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Tools;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\NervousSystem\Capability\Models\Tool;

/**
 * Fails when the catalog no longer matches the code.
 *
 * `capability_lookup` answers "does Kanvas have a tool for this?" out of `nervous_system_tools`, so a
 * catalog that drifted from the classes on disk makes an agent confidently wrong in the one place we
 * built it to be careful: a tool that exists but has no row reads as "nobody has built this", which
 * is a roadmap answer to a question that already had a solution.
 *
 * Run it in CI immediately after `sync-tools` — that ordering asserts the sync produced a complete
 * catalog, and catches a class discovery silently skipped (wrong base class, missing attribute)
 * rather than waiting for an agent to give a user the wrong answer.
 */
class CheckAgentToolDriftCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:check-tool-drift '
        . '{--allow-stale : Treat name/description/framework differences as warnings; still fail on missing or orphaned rows}';

    protected $description = 'Fail when the #[AgentTool] classes on disk and the nervous_system_tools catalog disagree.';

    public function handle(AgentToolDiscoveryService $discovery): int
    {
        $discovered = collect($discovery->discover())->keyBy('class');

        $rows = Tool::query()
            ->where('apps_id', 0)
            ->where('is_deleted', 0)
            ->get()
            ->keyBy('handler');

        $missing = $discovered->keys()->diff($rows->keys())->values();
        $orphaned = $rows->keys()->filter(fn (mixed $handler): bool => is_string($handler)
            && $handler !== ''
            && ! $discovered->has($handler))->values();
        $stale = $this->staleRows($discovered, $rows);

        $this->line(sprintf(
            'Discovered %d tool classes; catalog holds %d global rows.',
            $discovered->count(),
            $rows->count(),
        ));

        $this->report('Missing from the catalog (run sync-tools)', $missing);
        $this->report('Orphaned rows — class no longer exists (run sync-tools --prune)', $orphaned);
        $this->report('Stale rows — attributes differ from the class (run sync-tools --force)', $stale);

        $blocking = $missing->isNotEmpty() || $orphaned->isNotEmpty();

        if (! $this->option('allow-stale')) {
            $blocking = $blocking || $stale->isNotEmpty();
        }

        if ($blocking) {
            $this->error('Tool catalog has drifted from the code.');

            return self::FAILURE;
        }

        $this->info('Tool catalog matches the code.');

        return self::SUCCESS;
    }

    /**
     * Only the fields an agent actually reads. Version is deliberately excluded: it moves on its own
     * schedule and a bump with identical behaviour is not drift worth failing a build over.
     *
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $discovered
     * @param \Illuminate\Support\Collection<string, Tool> $rows
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function staleRows($discovered, $rows)
    {
        return $discovered
            ->filter(function (array $entry, string $class) use ($rows): bool {
                $row = $rows->get($class);

                if ($row === null) {
                    return false;
                }

                $frameworks = is_array($row->frameworks) ? $row->frameworks : [];
                sort($frameworks);
                $expected = $entry['frameworks'];
                sort($expected);

                return $row->name !== $entry['name']
                    || $row->description !== $entry['description']
                    || $frameworks !== $expected;
            })
            ->keys()
            ->values();
    }

    /**
     * @param \Illuminate\Support\Collection<int, string> $items
     */
    private function report(string $label, $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $this->warn(sprintf('%s: %d', $label, $items->count()));

        foreach ($items as $item) {
            $this->line('  - ' . $item);
        }
    }
}
