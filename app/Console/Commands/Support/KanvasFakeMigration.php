<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        $class = $this->argument('class');
        DB::table('migrations')->insert([
            'migration' => $class,
            'batch' => 1,
        ]);
        echo "Inserted $class into migrations table\n";
    }
}
