<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * One-time copy of the lead follows kept in social `users_follows` into crm `followers`.
 * Idempotent: a (lead, user) pair already in the new table is left as it is, so a follow removed
 * after the copy is not brought back. The social rows stay.
 */
class BackfillLeadFollowersCommand extends Command
{
    protected $signature = 'guild:backfill-lead-followers
                            {--app= : Only this app id}
                            {--chunk=1000 : users_follows rows per chunk}
                            {--dry-run : Report what would be copied without writing}';

    protected $description = 'Copy the lead follows in social users_follows into crm followers';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $copied = 0;
        $orphans = 0;

        UsersFollows::query()
            ->whereIn('entity_namespace', [Lead::class, SystemModules::getLegacyNamespace(Lead::class)])
            ->where('is_deleted', 0)
            ->when($this->option('app') !== null, fn ($query) => $query->where('apps_id', (int) $this->option('app')))
            ->chunkById(
                (int) $this->option('chunk'),
                function (Collection $follows) use ($dryRun, &$copied, &$orphans): void {
                    $leads = DB::connection('crm')->table('leads')
                        ->whereIn('id', $follows->pluck('entity_id')->map(fn ($id) => (int) $id)->unique())
                        ->get(['id', 'apps_id', 'companies_id'])
                        ->keyBy('id');

                    $rows = [];

                    foreach ($follows as $follow) {
                        $lead = $leads->get((int) $follow->entity_id);

                        if ($lead === null) {
                            $orphans++;

                            continue;
                        }

                        $rows[] = [
                            'apps_id' => $lead->apps_id,
                            'companies_id' => $lead->companies_id,
                            'users_id' => $follow->users_id,
                            'entity_id' => $lead->id,
                            'entity_namespace' => Lead::class,
                            'created_at' => $follow->created_at,
                            'updated_at' => $follow->updated_at ?? $follow->created_at,
                            'is_deleted' => 0,
                        ];
                    }

                    $copied += $dryRun
                        ? count($rows)
                        : Follower::query()->insertOrIgnore($rows);
                }
            );

        $this->info(sprintf(
            '%s %d lead follower(s); %d follow(s) pointed at a lead that no longer exists.',
            $dryRun ? 'Would copy up to' : 'Copied',
            $copied,
            $orphans
        ));

        return self::SUCCESS;
    }
}
