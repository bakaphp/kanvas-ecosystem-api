<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\PushToIntrasAction;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

/**
 * For people classified before the workflow rule existed. They will not fire the event again, since
 * their values no longer change.
 */
class PushPeopleClassificationToIntrasCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-push-people-classification
                            {app_id : The Intras app}
                            {company_id : Company whose people to push}
                            {--limit= : Stop after this many people}
                            {--dry-run : Count what would be pushed and write nothing}';

    protected $description = 'Write the applied people classification of a company to SIPGO';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $pushed = 0;
        $failed = 0;

        People::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->chunkById(500, function ($peoples) use ($limit, $dryRun, &$pushed, &$failed) {
                foreach ($peoples as $people) {
                    if ($limit !== null && $pushed >= $limit) {
                        return false;
                    }

                    $changes = ParticipantMapper::classificationChanges($people);

                    if ($changes === []) {
                        continue;
                    }

                    try {
                        if (! $dryRun) {
                            new PushToIntrasAction($people, $changes)->execute();
                        }

                        $pushed++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $this->warn("People {$people->getId()}: {$e->getMessage()}");
                    }
                }
            });

        $this->info(sprintf('%d person(s) %s, %d failed.', $pushed, $dryRun ? 'to push' : 'pushed', $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
