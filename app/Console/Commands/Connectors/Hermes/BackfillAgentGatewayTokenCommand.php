<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Hermes;

use Illuminate\Console\Command;
use Kanvas\Connectors\Hermes\Actions\BackfillAgentGatewayTokenAction;

class BackfillAgentGatewayTokenCommand extends Command
{
    protected $signature = 'kanvas:hermes-backfill-gateway-tokens';

    protected $description = 'Hoist per-deployment Hermes gateway tokens onto their owning agent custom field';

    public function handle(): int
    {
        /**
         * migrate existing gateway tokens from deployment custom field to agent custom field. See
         */
        $result = new BackfillAgentGatewayTokenAction()->execute();

        $this->info(sprintf(
            'Backfill complete — updated: %d, already-set: %d, no-source-token: %d',
            $result['updated'],
            $result['skipped_already_set'],
            $result['skipped_no_token'],
        ));

        return self::SUCCESS;
    }
}
