<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Customers\Models\People;

/**
 * The person's phones and emails are indexed on their own document and on every lead of theirs, and
 * neither is saved when only a contact row changes.
 */
final class ReindexPeopleSearchJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 60;
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly People $people
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->people->app);

        $this->people->searchable();
        $this->people->leads()->get()->searchable();
    }

    public function uniqueId(): string
    {
        return (string) $this->people->getId();
    }
}
