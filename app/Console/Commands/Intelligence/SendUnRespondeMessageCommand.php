<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;

class SendUnRespondeMessageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:send-unresponde-message {app_id}';

    public function handle(): void
    {
        $this->info('Command deprecated, this is now handled by a job dispatched by the workflow when the message is sent');
    }
}
