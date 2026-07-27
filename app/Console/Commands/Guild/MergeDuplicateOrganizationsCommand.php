<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\MergeOrganizationsAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\FindOrganizationDuplicatesService;
use Throwable;

/**
 * Conservatively consolidate duplicate Organization rows within one company — the ingest/
 * enrichment pipeline creates the same employer under casing/suffix/accent variants
 * ("Leaderville" / "LEADERVILLE SRL"), which then drives fake "job change" events.
 *
 * Only NORMALIZED-identical names are merged (suffix-stripped + case/accent-insensitive). It
 * never fuzzy-matches, so genuinely different companies that merely share a prefix
 * ("Alpha Industries" vs "Alpha Consulting") are left alone — those need human review via the
 * findOrganizationDuplicates / mergeOrganizations GraphQL flow.
 *
 * Dry-run by default; pass --force to apply. The oldest row in each group is the survivor.
 */
class MergeDuplicateOrganizationsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas:guild-merge-duplicate-organizations {app_id} {company_id} {--force : Apply merges (otherwise dry-run)} {--limit=5000 : Max duplicate groups to process}';

    /**
     * @var string|null
     */
    protected $description = 'Conservatively merge normalized-duplicate organizations (same name after suffix/casing/accent) within a company';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $apply = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));

        $groups = new FindOrganizationDuplicatesService()->generateByNormalizedName($app, $company, $limit);
        $this->line('Found ' . count($groups) . " normalized-duplicate organization groups for company {$company->name}");

        $mergedGroups = 0;
        $mergedOrgs = 0;
        $failed = 0;

        foreach ($groups as $group) {
            $target = $this->loadOrg($group->canonical_id, $app, $company);
            if ($target === null) {
                continue;
            }

            $groupMerged = 0;
            foreach ($group->member_ids as $memberId) {
                if ($memberId === $group->canonical_id) {
                    continue;
                }

                $source = $this->loadOrg($memberId, $app, $company);
                if ($source === null || (bool) $source->is_deleted) {
                    continue;
                }

                if (! $apply) {
                    $groupMerged++;
                    $mergedOrgs++;

                    continue;
                }

                try {
                    new MergeOrganizationsAction($source, $target)->execute();
                    $groupMerged++;
                    $mergedOrgs++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            }

            if ($groupMerged > 0) {
                $mergedGroups++;
                $verb = $apply ? 'merged' : 'dry-run';
                $this->line("[{$verb}] '{$group->sample_name}' <- {$groupMerged} duplicate(s)");
            }
        }

        $mode = $apply ? 'APPLIED' : 'DRY-RUN';
        $this->line("[{$mode}] groups {$mergedGroups}, orgs merged {$mergedOrgs}, failed {$failed}");

        if (! $apply) {
            $this->line('Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }

    private function loadOrg(int $id, Apps $app, Companies $company): ?Organization
    {
        return Organization::query()
            ->where('id', $id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();
    }
}
