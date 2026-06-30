<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
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

    /** @var array<int, string[]> memoized normalized current-employer names per person */
    private array $currentEmployersCache = [];

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyId = $this->option('company_id');
        $company = $companyId !== null ? Companies::getById((int) $companyId) : null;

        $apply = (bool) $this->option('force');
        $pruneEmpty = (bool) $this->option('prune-empty');

        $repaired = 0;
        $pruned = 0;
        $scanned = 0;

        Event::query()
            ->fromApp($app)
            ->where('event_type', 'people.enriched')
            ->where('source_entity_type', People::class)
            ->when($company !== null, fn (Builder $q): Builder => $q->where('companies_id', $company->getId()))
            ->orderBy('id')
            ->chunkById(500, function ($events) use ($apply, $pruneEmpty, &$repaired, &$pruned, &$scanned): void {
                foreach ($events as $event) {
                    $scanned++;

                    $payload = (array) ($event->payload ?? []);
                    $changes = (array) ($payload['changes'] ?? []);

                    $cleaned = $this->cleanChanges($changes, (int) $event->source_entity_id);

                    if ($cleaned === $changes) {
                        continue;
                    }

                    if ($cleaned === [] && $pruneEmpty) {
                        $pruned++;
                        if ($apply) {
                            $event->delete();
                        }

                        continue;
                    }

                    $repaired++;
                    if ($apply) {
                        $payload['changes'] = $cleaned;
                        $payload['changed_fields'] = array_keys($cleaned);
                        $event->payload = $payload;
                        $event->save();
                    }
                }
            });

        $mode = $apply ? 'APPLIED' : 'DRY-RUN';
        $this->line("[{$mode}] scanned {$scanned}, repaired {$repaired}" . ($pruneEmpty ? ", pruned {$pruned}" : ''));

        if (! $apply) {
            $this->line('Re-run with --force to write these changes.');
        }

        return self::SUCCESS;
    }

    /**
     * Drop the transition keys that aren't a true move; leave every other signal
     * (new_account, seniority_promoted, email_changed, contacts_added, location_added)
     * untouched.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function cleanChanges(array $changes, int $peopleId): array
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

            // A current_employer move is only true when `to` is the person's genuine current
            // employer. Otherwise it's a job they already left — a false move to a past role.
            if ($key === 'current_employer' && is_string($to) && ! $this->isGenuineCurrentEmployer($peopleId, $to)) {
                unset($changes[$key]);
            }
        }

        return $changes;
    }

    /**
     * Is `$toCompany` one of the person's current (status=1) employers? When we have no
     * employment history on file we can't disprove it, so we keep the row rather than risk
     * destroying a genuine move.
     */
    private function isGenuineCurrentEmployer(int $peopleId, string $toCompany): bool
    {
        $current = $this->currentEmployersFor($peopleId);

        if ($current === []) {
            return true;
        }

        return in_array($this->normalizeName($toCompany), $current, true);
    }

    /**
     * Normalized names of the person's current (status=1) employers, memoized per person.
     *
     * @return string[]
     */
    private function currentEmployersFor(int $peopleId): array
    {
        if (array_key_exists($peopleId, $this->currentEmployersCache)) {
            return $this->currentEmployersCache[$peopleId];
        }

        $orgIds = PeopleEmploymentHistory::query()
            ->where('peoples_id', $peopleId)
            ->where('status', 1)
            ->where('is_deleted', 0)
            ->pluck('organizations_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $names = $orgIds === []
            ? []
            : Organization::query()->whereIn('id', $orgIds)->pluck('name')->all();

        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($name) => $this->normalizeName((string) $name),
            $names,
        ))));

        return $this->currentEmployersCache[$peopleId] = $normalized;
    }

    /**
     * Strip legal suffixes (SRL, S.A., …) and lowercase so "Baninter" matches a stored
     * "BANINTER SRL" — the same normalizer the org create-path uses.
     */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(OrganizationNameNormalizerService::normalize($name)));
    }
}
