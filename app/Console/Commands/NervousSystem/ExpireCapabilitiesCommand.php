<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\NervousSystem\Capability\Actions\ExpireCapabilitiesAction;
use Throwable;

class ExpireCapabilitiesCommand extends Command
{
    protected $signature = 'nervous-system:expire-capabilities';

    protected $description = 'Sweep agent skill/tool grants past their expires_at timestamp, mark them inactive, and emit skill.expired / tool.expired ledger events';

    public function handle(): int
    {
        try {
            $result = new ExpireCapabilitiesAction()->execute();
        } catch (Throwable $e) {
            $this->error('Capability expiry failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Expired %d skill grants and %d tool grants.',
            $result['skills_expired'],
            $result['tools_expired'],
        ));

        return self::SUCCESS;
    }
}
