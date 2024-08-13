<?php

declare(strict_types=1);

namespace Kanvas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Log;

class BatchLoggerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use KanvasJobsTrait;

    public function __construct(
        public string $message,
    ) {
        $this->onQueue('batch-logger');
    }

    public function handle(): void
    {
        Log::channel('api_requests')->info($this->message);
    }
}
