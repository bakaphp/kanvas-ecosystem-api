<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Enums\AppEnums;

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

        return;
    }
}
