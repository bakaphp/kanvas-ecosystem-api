<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;

class KanvasIndexCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:index {system_module} {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Index the Kanvas system module';

    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $systemModule = $this->argument('system_module');
        if (! class_exists($systemModule)) {
            $this->error("The system module {$systemModule} does not exist.");

            return;
        }

        $data = $systemModule::fromApp($app)
            ->orderBy('id', 'DESC')
            ->cursor();
        $i = 0;
        foreach ($data as $item) {
            try {
                $item->searchable();
                $i++;
            } catch (\Exception $e) {
                $this->error("Error reindexing item ID: {$item->id} - " . $e->getMessage());
            }
        }

        $this->info('Total products to reindexed: ' . $i);
    }
}
