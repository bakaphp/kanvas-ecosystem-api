<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\PasoRapido;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PasoRapido\Jobs\StorePasoRapidoInvoicePdfJob;
use Kanvas\Souk\Orders\Models\Order;

class StorePasoRapidoInvoicePdfsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-paso-rapido:store-invoice-pdfs
        {app_id}
        {--since= : Only orders created on or after this date (Y-m-d)}
        {--limit= : Max orders to queue}';

    protected $description = 'Queue a copy of the Paso Rapido invoice PDF into our storage for paid orders that do not have one yet';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $orders = Order::query()
            ->fromApp($app)
            ->where('metadata', 'like', '%"invoice"%')
            ->when($this->option('since'), fn ($q, $since) => $q->where('created_at', '>=', $since))
            ->lazyById(200)
            ->filter(fn (Order $order) => Str::trimToNull($order->metadata['data']['invoice']['pdf'] ?? null) !== null
                && Str::trimToNull($order->metadata['data']['invoice']['pdf_url'] ?? null) === null);

        if ($this->option('limit')) {
            $orders = $orders->take((int) $this->option('limit'));
        }

        $queued = 0;
        foreach ($orders as $order) {
            StorePasoRapidoInvoicePdfJob::dispatch($order);
            $queued++;
        }

        $this->info("Queued {$queued} orders.");

        return self::SUCCESS;
    }
}
