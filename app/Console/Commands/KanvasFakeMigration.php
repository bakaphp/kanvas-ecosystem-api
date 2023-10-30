<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Integrations\Zoho\Workflows\ZohoLeadWorkflow;
use Workflow\WorkflowStub;

class KanvasFakeMigration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:fake-migration {class}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'insert a fake migration into the migrations table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $workflow = WorkflowStub::make(ZohoLeadWorkflow::class);
        $workflow->start(Lead::first());

        print_r($workflow);
        die();

        $class = $this->argument('class');
        DB::table('migrations')->insert([
            'migration' => $class,
            'batch' => 1,
        ]);
        echo "Inserted $class into migrations table\n";
    }
}
