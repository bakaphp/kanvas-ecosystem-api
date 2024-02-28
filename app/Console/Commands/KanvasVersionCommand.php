<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Workflows\ZohoAgentActivity;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\StoredWorkflow;

class KanvasVersionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:version';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Whats the current version of kanvas niche you are running';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->newLine();
        $this->info('Kanvas Niche is running version : ' . AppEnums::VERSION->getValue());
        $this->newLine();
        $app = Apps::getById(9);
        App::scoped(Apps::class, function () use ($app) {
            return $app;
        });
        $activity = new ZohoAgentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute(Users::getById(11148), Apps::getById(9), ['company' => Companies::getById(7855)]);

        return;
    }
}
