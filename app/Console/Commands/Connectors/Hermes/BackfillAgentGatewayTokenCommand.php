<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Hermes;

use Illuminate\Console\Command;
use Kanvas\Connectors\Hermes\Actions\BackfillAgentGatewayTokenAction;

/**
 * One-off backfill — copy each Hermes agent's existing per-deployment gateway token onto the
 * agent custom field so the next launch reuses it instead of regenerating. Idempotent; safe
 * to re-run. See {@see BackfillAgentGatewayTokenAction} for the per-agent logic.
 *
 * Run once after deploying the agent-only resolveGatewayToken() change. Not scheduled —
 * intentional one-off.
 *
 *   php artisan kanvas:hermes-backfill-gateway-tokens
 */
class BackfillAgentGatewayTokenCommand extends Command
{
    protected $signature = 'kanvas:hermes-backfill-gateway-tokens';

    protected $description = 'Hoist per-deployment Hermes gateway tokens onto their owning agent custom field';

    public function handle(): int
    {
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
