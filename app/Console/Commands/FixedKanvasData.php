<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Fixed\FixedDefaultCompany;

class FixedKanvasData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:fixed-kanvas-data {fixed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fixed = $this->argument('fixed');

        switch ($fixed) {
            case 'FixedDefaultCompany':
                FixedDefaultCompany::execute();

                break;
            default:
                $this->error('Fixed not found');

                break;
        }
    }
}
