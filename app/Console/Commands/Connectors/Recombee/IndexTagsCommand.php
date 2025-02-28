<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Social\Tags\Models\Tag;

class IndexTagsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:recombee-index-tags {app_id} {companies_id}';

    protected $description = 'Index tags to the recommendation engine';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        /** @var Apps $app  */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::find($this->argument('companies_id'));

        $query = Tag::fromApp($app)
            ->where('is_deleted', 0)
            ->where('companies_id', $company->getId())
            ->orderBy('id', 'DESC');
        $cursor = $query->cursor();
        $totalTags = $query->count();

        $this->output->progressStart($totalTags);
        $tagsIndex = new RecombeeIndexService(
            $app,
        );

        foreach ($cursor as $tag) {
            $result = $tagsIndex->indexTags($tag);

            $this->info('Tag ID: ' . $tag->getId() . ' indexed with result: ' . $result);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        return;
    }
}
