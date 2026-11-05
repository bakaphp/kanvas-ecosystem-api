<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Classification\Actions\ClassifyPeopleAction;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationSettings;
use Kanvas\Guild\Customers\Classification\Services\PeopleClassificationTagsService;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

class ClassifyPeopleJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 300;
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Apps $app,
        public readonly People $people,
        public readonly bool $force = false
    ) {
        $this->afterCommit();
    }

    /**
     * Saving a person must not fail because of this. A current person still has its tags reconciled:
     * UpdatePeopleAction syncs tags to whatever the form sent, which drops classification tags without
     * changing the title.
     */
    public static function dispatchIfEnabled(People $people): void
    {
        // Legacy rows (apps 1/2 era) carry no apps_id until the backfill runs; there is no app to read settings from.
        if ($people->app === null) {
            return;
        }

        try {
            $settings = PeopleClassificationSettings::for($people->app, $people->company);

            if (! $settings->isActive()) {
                return;
            }

            if (! ClassifyPeopleAction::isCurrent($people, $settings->taxonomy)) {
                self::dispatch($people->app, $people);

                return;
            }

            if ($settings->applyTags) {
                PeopleClassificationTagsService::reconcile($people, $settings->taxonomy);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->people->getId();
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new ClassifyPeopleAction($this->people, $this->force)->execute();
    }
}
