<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Connectors\PromptMine\Services\RecombeeIndexService;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

class IndexTagsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:recombee-index-tags {app_id} {companies_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index tags to the recommendation engine';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::find($this->argument('companies_id'));
        // $this->overwriteAppService($app);

        $query = Tag::fromApp($app)
        ->where('is_deleted', 0)
        ->where('companies_id', $company->getId())
        ->orderBy('id', 'DESC');
        $cursor = $query->cursor();
        $totalTags = $query->count();

        $this->output->progressStart($totalTags);
        $tagsIndex = new RecombeeIndexService(
            $app,
            $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_DATABASE->value),
            $app->get(ConfigurationEnum::FOLLOWS_ENGINE_RECOMBEE_API_KEY->value),
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
