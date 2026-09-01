<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Repositories\ApprovalPolicyRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Throwable;

/**
 * Opens generic approval_requests rows for items that were already sitting PENDING in the legacy
 * accounting.approval_queue when a tenant's policy was seeded.
 *
 * Only OPEN items are migrated. Closed ones stay where they are — approval_queue remains the
 * accounting audit of what happened before the cutover, and rewriting settled history into a second
 * place would create two answers to the same question.
 *
 * Idempotent: an item that already has a matching open request is skipped, so this is safe to re-run.
 */
class BackfillApprovalRequestsCommand extends Command
{
    use KanvasJobsTrait;

    private const array TARGET_MODELS = [
        'bill' => Bill::class,
        'invoice' => Invoice::class,
        'expense' => Expense::class,
    ];

    protected $signature = 'kanvas:approvals:backfill {apps_id} {--company_id=} {--dry-run}';

    protected $description = 'Opens generic approval requests for still-pending legacy approval_queue items';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $dryRun = (bool) $this->option('dry-run');
        $migrated = 0;
        $skipped = 0;

        ApprovalQueueItem::query()
            ->where('apps_id', $app->getId())
            ->when($this->option('company_id'), fn ($q) => $q->where('companies_id', (int) $this->option('company_id')))
            ->where('status', ApprovalQueueStatusEnum::PENDING->value)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->chunkById(100, function ($items) use (&$migrated, &$skipped, $dryRun): void {
                foreach ($items as $item) {
                    match ($this->migrate($item, $dryRun)) {
                        true => $migrated++,
                        false => $skipped++,
                    };
                }
            });

        $verb = $dryRun ? 'would migrate' : 'migrated';
        $this->info("Done. {$verb} {$migrated} item(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function migrate(ApprovalQueueItem $item, bool $dryRun): bool
    {
        $modelName = self::TARGET_MODELS[$item->target_type] ?? null;

        if ($modelName === null) {
            $this->warn("Item {$item->id}: no model registered for target_type \"{$item->target_type}\".");

            return false;
        }

        $entity = $modelName::query()->find($item->target_id);

        if ($entity === null) {
            $this->warn("Item {$item->id}: {$item->target_type} {$item->target_id} no longer exists.");

            return false;
        }

        try {
            $policy = ApprovalPolicyRepository::findByType($entity, $item->action_type);

            if ($policy === null) {
                $this->warn("Item {$item->id}: no policy for {$item->action_type}. Seed policies first.");

                return false;
            }

            if ($this->alreadyOpen($item)) {
                return false;
            }

            if ($dryRun) {
                $this->line("Would open a request for {$item->target_type} {$item->target_id}.");

                return true;
            }

            $request = new RequestApprovalAction(
                entity: $entity,
                policy: $policy,
                origin: ApprovalOriginEnum::IMPORT,
                payload: (array) ($item->payload ?? []),
            )->execute();

            $note = $request->isUnassigned() ? ' (UNASSIGNED — nobody resolved)' : '';
            $this->line("Opened request {$request->getId()} for {$item->target_type} {$item->target_id}{$note}.");

            return true;
        } catch (Throwable $e) {
            $this->error("Item {$item->id}: {$e->getMessage()}");

            return false;
        }
    }

    private function alreadyOpen(ApprovalQueueItem $item): bool
    {
        return ApprovalRequest::query()
            ->where('apps_id', $item->apps_id)
            ->where('companies_id', $item->companies_id)
            ->where('approval_type', $item->action_type)
            ->where('entity_id', $item->target_id)
            ->where('status', ApprovalStatusEnum::PENDING->value)
            ->where('is_deleted', false)
            ->exists();
    }
}
