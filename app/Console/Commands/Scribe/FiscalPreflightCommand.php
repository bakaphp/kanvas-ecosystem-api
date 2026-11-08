<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Webhooks\AlanubeEmissionFinishedWebhookJob;
use Kanvas\Connectors\WebPos\Webhooks\WebPosResultWebhookJob;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\PollEcfResultJob;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Run before migrating to the fiscal tables (docs/accounting/fiscal-documents-table-plan.md §8). Fails the
 * deploy when production e-CF state still lives in `regional_compliance.do_ecf`, when e-CF jobs are still
 * queued (they carry the old payload), or when a production company has provider webhooks registered —
 * any of those would be lost or misrouted by the switch. Test-environment blocks are only reported; the
 * backfill command moves them.
 */
class FiscalPreflightCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:fiscal-preflight
                            {--company=* : Only these company ids (default: every company)}
                            {--queue=* : Queue names to inspect for pending e-CF jobs (default: the configured default queue)}
                            {--skip-queue-check : Do not inspect the queues}';

    protected $description = 'Check that no production e-CF state or pending e-CF job blocks the move to fiscal_documents.';

    private const array JOB_CLASSES = [SubmitEcfJob::class, PollEcfResultJob::class];

    private const array WEBHOOK_JOBS = [WebPosResultWebhookJob::class, AlanubeEmissionFinishedWebhookJob::class];

    /**
     * @var array<int, string>
     */
    private array $environments = [];

    public function handle(): int
    {
        $blocking = [];
        $counts = ['production' => 0, 'test' => 0, 'production_provider_refs' => 0];

        foreach ([Invoice::class, Bill::class, Expense::class] as $model) {
            $rows = $model::query()
                ->when($this->companyIds() !== [], fn ($query) => $query->whereIn('companies_id', $this->companyIds()))
                ->whereNotNull('regional_compliance->' . EcfCompliance::KEY . '->encf')
                ->selectRaw('apps_id, companies_id, count(*) as total')
                ->selectRaw("sum(case when json_extract(regional_compliance, '$.do_ecf.provider_ref') is not null then 1 else 0 end) as with_ref")
                ->groupBy('apps_id', 'companies_id')
                ->get();

            foreach ($rows as $row) {
                $environment = $this->environmentOf((int) $row->apps_id, (int) $row->companies_id);
                $counts[$environment] += (int) $row->total;

                if ($environment === EcfEnvironmentEnum::PRODUCTION->value) {
                    $counts['production_provider_refs'] += (int) $row->with_ref;
                    $blocking[] = sprintf(
                        '%s: %d production do_ecf block(s) in company %d (%d with a provider reference)',
                        class_basename($model),
                        (int) $row->total,
                        (int) $row->companies_id,
                        (int) $row->with_ref
                    );
                }
            }
        }

        $pendingJobs = $this->option('skip-queue-check') ? 0 : $this->pendingEcfJobs();
        if ($pendingJobs > 0) {
            $blocking[] = "{$pendingJobs} e-CF job(s) still queued; drain the queue first.";
        }

        foreach (self::WEBHOOK_JOBS as $jobClass) {
            $receivers = ReceiverWebhook::query()
                ->forJob($jobClass)
                ->where('is_deleted', 0)
                ->when($this->companyIds() !== [], fn ($query) => $query->whereIn('companies_id', $this->companyIds()))
                ->get();

            foreach ($receivers as $receiver) {
                if ($this->environmentOf((int) $receiver->apps_id, (int) $receiver->companies_id) === EcfEnvironmentEnum::PRODUCTION->value) {
                    $blocking[] = class_basename($jobClass) . " receiver {$receiver->uuid} is registered for production company {$receiver->companies_id}.";
                }
            }
        }

        $this->table(['production blocks', 'test blocks', 'production provider refs', 'queued e-CF jobs'], [[
            $counts['production'],
            $counts['test'],
            $counts['production_provider_refs'],
            $pendingJobs,
        ]]);

        if ($blocking !== []) {
            foreach ($blocking as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        $this->info('Preflight passed. Test-environment blocks: run scribe:fiscal-backfill after migrating.');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function companyIds(): array
    {
        return array_values(array_map('intval', (array) $this->option('company')));
    }

    private function environmentOf(int $appsId, int $companiesId): string
    {
        if (isset($this->environments[$companiesId])) {
            return $this->environments[$companiesId];
        }

        $app = Apps::find($appsId);
        if ($app !== null) {
            $this->overwriteAppService($app);
        }

        $company = Companies::find($companiesId);

        return $this->environments[$companiesId] = $company !== null
            ? EcfEnvironmentEnum::forCompany($company)->value
            : EcfEnvironmentEnum::TEST->value;
    }

    /**
     * Ready, delayed and reserved jobs on the redis queues: any of them still carries the pre-fiscal payload.
     */
    private function pendingEcfJobs(): int
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'redis') {
            return 0;
        }

        $queues = (array) $this->option('queue') ?: [(string) config("queue.connections.{$connection}.queue", 'default')];
        $redis = Redis::connection((string) config("queue.connections.{$connection}.connection", 'default'));
        $pending = 0;

        foreach ($queues as $queue) {
            $payloads = [
                ...(array) $redis->lrange("queues:{$queue}", 0, -1),
                ...(array) $redis->zrange("queues:{$queue}:delayed", 0, -1),
                ...(array) $redis->zrange("queues:{$queue}:reserved", 0, -1),
            ];

            foreach ($payloads as $payload) {
                foreach (self::JOB_CLASSES as $jobClass) {
                    if (str_contains((string) $payload, str_replace('\\', '\\\\', $jobClass))
                        || str_contains((string) $payload, $jobClass)) {
                        $pending++;

                        continue 2;
                    }
                }
            }
        }

        return $pending;
    }
}
