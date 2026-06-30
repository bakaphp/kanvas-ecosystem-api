<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Connectors\Apollo\Services\PersonCurrentEmployerService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\Models\Event;

/**
 * One-time repair for historical `people.enriched` events whose payload recorded a
 * fake "Antes → Después" row. Two kinds of fake are stripped:
 *
 *   1. Empty / equal transitions — `current_employer` / `title` / `headline` where
 *      `from` was empty (a first-time fill) or equal to `to`.
 *   2. Moves to a PAST employer — a `current_employer.to` that isn't the person's
 *      genuine current employer (per peoples_employment_history status=1). Apollo
 *      returns the full history, so a job left years ago (e.g. Baninter, ended 2001)
 *      could be written as a "move" even though the person never left their real
 *      current employer (e.g. Alpha).
 *
 * The live emitter no longer writes either kind (see EnrichPeopleFromApolloAction),
 * but old rows still pollute the "Registro de cambios" feed. We rewrite rather than
 * delete so the cleanup report's distinct-person "verified" count is preserved (an
 * event left with zero real changes renders no feed row). `--prune-empty` additionally
 * deletes events that end up with no real change at all.
 *
 * Dry-run by default; pass --force to write.
 */
class CleanEnrichmentChangeNoiseCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas:guild-apollo-clean-enrichment-noise {app_id} {--company_id= : Limit to one company} {--force : Apply changes (otherwise dry-run)} {--prune-empty : Delete events left with no real change}';

    /**
     * @var string|null
     */
    protected $description = 'Strip fake (empty/equal/past-employer) before→after changes from historical people.enriched ledger events';

    private const array TRANSITION_KEYS = ['current_employer', 'title', 'headline'];

    private int $pastEmployerStripped = 0;

    public function handle(): int
    {
        $employerResolver = new PersonCurrentEmployerService();

        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyId = $this->option('company_id');
        $company = $companyId !== null ? Companies::getById((int) $companyId) : null;

        $apply = (bool) $this->option('force');
        $pruneEmpty = (bool) $this->option('prune-empty');

        $emptied = 0;
        $partial = 0;
        $pruned = 0;
        $scanned = 0;

        Event::query()
            ->fromApp($app)
            ->where('event_type', 'people.enriched')
            ->where('source_entity_type', People::class)
            ->when($company !== null, fn (Builder $q): Builder => $q->where('companies_id', $company->getId()))
            ->orderBy('id')
            ->chunkById(500, function ($events) use ($apply, $pruneEmpty, $employerResolver, &$emptied, &$partial, &$pruned, &$scanned): void {
                foreach ($events as $event) {
                    $scanned++;

                    $payload = (array) ($event->payload ?? []);
                    $changes = (array) ($payload['changes'] ?? []);

                    $cleaned = $this->cleanChanges($changes, (int) $event->source_entity_id, $employerResolver);

                    if ($cleaned === $changes) {
                        continue;
                    }

                    $becameEmpty = $cleaned === [];
                    $becameEmpty ? $emptied++ : $partial++;

                    if ($becameEmpty && $pruneEmpty) {
                        $pruned++;
                        if ($apply) {
                            $event->delete();
                        }

                        continue;
                    }

                    if ($apply) {
                        $payload['changes'] = $cleaned;
                        $payload['changed_fields'] = array_keys($cleaned);
                        $event->payload = $payload;
                        $event->save();
                    }
                }
            });

        $mode = $apply ? 'APPLIED' : 'DRY-RUN';
        $repaired = $emptied + $partial;
        $this->line("[{$mode}] scanned {$scanned}, repaired {$repaired} (emptied {$emptied}, kept-partial {$partial}); of those, {$this->pastEmployerStripped} past-employer false-moves" . ($pruneEmpty ? ", pruned {$pruned}" : ''));

        if (! $apply) {
            $this->line('Re-run with --force to write these changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Only TRANSITION_KEYS are touched — every other signal (new_account, email_changed,
     * contacts_added, …) is left intact.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function cleanChanges(array $changes, int $peopleId, PersonCurrentEmployerService $employerResolver): array
    {
        foreach (self::TRANSITION_KEYS as $key) {
            if (! isset($changes[$key]) || ! is_array($changes[$key])) {
                continue;
            }

            $from = $changes[$key]['from'] ?? null;
            $to = $changes[$key]['to'] ?? null;

            if (! EnrichPeopleFromApolloAction::isRealTransition(
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            )) {
                unset($changes[$key]);

                continue;
            }

            if ($key === 'current_employer' && is_string($to) && ! $employerResolver->isGenuineCurrentEmployer($peopleId, $to)) {
                unset($changes[$key]);
                $this->pastEmployerStripped++;
            }
        }

        return $changes;
    }
}
