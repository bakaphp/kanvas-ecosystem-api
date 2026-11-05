<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Classification\Actions\ClassifyPeopleAction;
use Kanvas\Guild\Customers\Classification\Jobs\ClassifyPeopleJob;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationSettings;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

/**
 * Runs whether or not the company's flag is on, so quality can be sampled before turning it on. It
 * only needs a taxonomy.
 */
class ClassifyPeopleCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:classify-people
                            {app_id : App whose people to classify}
                            {company_id : Company whose people to classify}
                            {--limit= : Stop after this many people needing classification}
                            {--force : Reclassify unchanged people, a manual choice is still kept}
                            {--sync : Run inline and print each result, to sample quality}';

    protected $description = 'Classify people into the seniority level and functional area of the company taxonomy';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));
        $taxonomy = PeopleClassificationSettings::for($app, $company)->taxonomy;

        if ($taxonomy === null) {
            $this->error('No taxonomy: set people_classification_taxonomy on the app or company first.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        People::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->chunkById(500, function ($peoples) use (
                $app,
                $taxonomy,
                $limit,
                $force,
                $sync,
                &$processed,
                &$skipped,
                &$failed
            ) {
                foreach ($peoples as $people) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    if (! $force && ClassifyPeopleAction::isCurrent($people, $taxonomy)) {
                        $skipped++;

                        continue;
                    }

                    $processed++;

                    if (! $sync) {
                        ClassifyPeopleJob::dispatch($app, $people, $force);

                        continue;
                    }

                    try {
                        $this->printResult($people, new ClassifyPeopleAction($people, $force)->execute());
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $this->warn("People {$people->getId()}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(sprintf(
            '%d person(s) %s, %d skipped, %d failed.',
            $processed,
            $sync ? 'classified' : 'queued',
            $skipped,
            $failed,
        ));

        return self::SUCCESS;
    }

    private function printResult(People $people, array $result): void
    {
        $this->line(sprintf(
            '%d | %s | %s | %s | %s | %s',
            $people->getId(),
            $result['title'] ?? '-',
            $result['level'] ?? '-',
            $result['area'] ?? '-',
            $result['confidence'] ?? '-',
            $result['status'],
        ));
    }
}
