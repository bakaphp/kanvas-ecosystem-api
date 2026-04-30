<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;

class AppendToLedgerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly EventData $data,
    ) {
        $this->onQueue('ledger');
    }

    public function handle(): void
    {
        new AppendEventAction($this->data)->execute();
    }
}
