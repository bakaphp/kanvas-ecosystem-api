<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Bouncer;

class CreateRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'role:create {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $role = Bouncer::role()->firstOrCreate([
            'name' => $this->argument('name'),
            'title' => $this->argument('name'),
        ]);
    }
}
