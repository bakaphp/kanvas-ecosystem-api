<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Actions\CreateChannel;
use Kanvas\Inventory\Channels\DataTransferObject\Channels as ChannelsDto;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusDto;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Import the JSONL produced by `kanvas-inventory:export-products-cross-env` into a destination app/company.
 *
 * The file references warehouses / channels / status by name. This command first creates any missing ones
 * in the destination company (idempotent firstOrCreate) and builds name → destination-id maps, then rewrites
 * every variant to the destination ids before handing it to the existing ProductImporterAction pipeline.
 * No source ids ever cross over.
 */
class ImportProductsCrossEnvCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-inventory:import-products-cross-env
        {app_id}
        {company_id}
        {--file= : Path to the JSONL export (absolute, or relative to the local storage disk)}
        {--user-id= : Destination user id; must belong to the destination company and be an admin to preserve is_published}
        {--region= : Destination region id; defaults to the company default region}
        {--run-workflow : Fire the product CREATED workflow on import (off by default)}
        {--skip-files : Do not import product/variant images}';

    /** @var array<string, int> */
    private array $warehouseMap = [];

    /** @var array<string, int> */
    private array $channelMap = [];

    /** @var array<string, int> */
    private array $statusMap = [];

    protected $description = 'Import a cross-environment inventory JSONL export, remapping names to destination ids';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        Bouncer::scope()->to(RolesEnums::getScope($app));

        $company = Companies::getById((int) $this->argument('company_id'));

        $userId = (int) $this->option('user-id');
        if ($userId === 0) {
            $this->error('--user-id is required (a user that belongs to company ' . $company->getId() . ').');

            return self::FAILURE;
        }
        $user = Users::getById($userId);
        auth()->setUser($user);

        $region = $this->option('region')
            ? RegionRepository::getById((int) $this->option('region'), $company)
            : Regions::getDefault($company, $app);

        if ($region === null) {
            $this->error('No region found for the destination company. Create a default region first or pass --region.');

            return self::FAILURE;
        }

        $filePath = $this->resolveFilePath((string) $this->option('file'));
        if ($filePath === null) {
            $this->error('--file not found: ' . $this->option('file'));

            return self::FAILURE;
        }

        $runWorkflow = (bool) $this->option('run-workflow');
        $skipFiles = (bool) $this->option('skip-files');

        $total = $this->buildNameMaps($filePath, $company, $user, $app, $region);
        $this->line(sprintf(
            'Resolved %d warehouse(s), %d channel(s), %d status(es) in destination.',
            count($this->warehouseMap),
            count($this->channelMap),
            count($this->statusMap)
        ));

        $failuresPath = $filePath . '.failures.jsonl';
        $failures = fopen($failuresPath, 'w');
        $created = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $handle = fopen($filePath, 'r');
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);
            if (! is_array($record)) {
                continue;
            }

            try {
                $payload = $this->remapRecord($record, $skipFiles);

                new ProductImporterAction(
                    ProductImporter::from($payload),
                    $company,
                    $user,
                    $region,
                    $app,
                    $runWorkflow,
                )->execute();

                $created++;
            } catch (Throwable $e) {
                $failed++;
                fwrite($failures, json_encode([
                    'slug' => $record['slug'] ?? null,
                    'name' => $record['name'] ?? null,
                    'error' => $e->getMessage(),
                ]) . PHP_EOL);
            }

            $bar->advance();
        }

        $bar->finish();
        fclose($handle);
        fclose($failures);
        $this->newLine(2);

        $this->info('Cross-environment import complete.');
        $this->line('App: ' . $app->name . ' (' . $app->getId() . ')');
        $this->line('Company: ' . $company->name . ' (' . $company->getId() . ')');
        $this->line('Created/updated: ' . $created);
        $this->line('Failed: ' . $failed);
        if ($failed > 0) {
            $this->line('Failures log: ' . $failuresPath);
        }

        return self::SUCCESS;
    }

    /**
     * First streamed pass: collect every referenced warehouse/channel/status name, create any that are
     * missing in the destination company, and build name → destination-id maps. Returns the product count.
     */
    private function buildNameMaps(
        string $filePath,
        Companies $company,
        Users $user,
        Apps $app,
        Regions $region
    ): int {
        $warehouseNames = [];
        $channelNames = [];
        $statusNames = [];
        $count = 0;

        $handle = fopen($filePath, 'r');
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (! is_array($record)) {
                continue;
            }
            $count++;

            if (! empty($record['status'])) {
                $statusNames[$record['status']] = true;
            }

            foreach ($record['variants'] ?? [] as $variant) {
                if (! empty($variant['status_name'])) {
                    $statusNames[$variant['status_name']] = true;
                }
                foreach ($variant['warehouses'] ?? [] as $warehouse) {
                    if (! empty($warehouse['warehouse_name'])) {
                        $warehouseNames[$warehouse['warehouse_name']] = true;
                    }
                    if (! empty($warehouse['status_name'])) {
                        $statusNames[$warehouse['status_name']] = true;
                    }
                }
                foreach ($variant['channels'] ?? [] as $channel) {
                    if (! empty($channel['channel_name'])) {
                        $channelNames[$channel['channel_name']] = true;
                    }
                    if (! empty($channel['warehouse_name'])) {
                        $warehouseNames[$channel['warehouse_name']] = true;
                    }
                }
            }
        }
        fclose($handle);

        foreach (array_keys($warehouseNames) as $name) {
            $warehouse = new CreateWarehouseAction(
                new WarehousesDto(
                    company: $company,
                    app: $app,
                    user: $user,
                    region: $region,
                    name: (string) $name,
                ),
                $user
            )->execute();
            $this->warehouseMap[(string) $name] = $warehouse->getId();
        }

        foreach (array_keys($channelNames) as $name) {
            $channel = new CreateChannel(
                new ChannelsDto(
                    app: $app,
                    company: $company,
                    user: $user,
                    name: (string) $name,
                ),
                $user
            )->execute();
            $this->channelMap[(string) $name] = $channel->getId();
        }

        foreach (array_keys($statusNames) as $name) {
            $status = new CreateStatusAction(
                new StatusDto(
                    app: $app,
                    company: $company,
                    user: $user,
                    name: (string) $name,
                ),
                $user
            )->execute();
            $this->statusMap[(string) $name] = $status->getId();
        }

        return $count;
    }

    /**
     * Rewrite a single exported record into the canonical ProductImporter shape, translating every
     * warehouse/channel/status name into the destination ids resolved in buildNameMaps().
     */
    private function remapRecord(array $record, bool $skipFiles): array
    {
        $productWarehouseNames = [];

        foreach ($record['variants'] ?? [] as $index => $variant) {
            if (! empty($variant['status_name']) && isset($this->statusMap[$variant['status_name']])) {
                $variant['status'] = ['id' => $this->statusMap[$variant['status_name']]];
            }
            unset($variant['status_name']);

            $warehouses = [];
            foreach ($variant['warehouses'] ?? [] as $warehouse) {
                $name = $warehouse['warehouse_name'] ?? null;
                if ($name === null || ! isset($this->warehouseMap[$name])) {
                    continue;
                }
                $productWarehouseNames[$name] = true;
                $warehouse['id'] = $this->warehouseMap[$name];

                // Intentionally do NOT pass a per-warehouse 'status'. WarehouseService::addToWarehouses()
                // has a bug where setting it does `$status = ...->getId()` then `$status->getId()` again
                // ("Call to a member function getId() on int"). Omitting it falls back to the company
                // default status, which is what we want for a seed anyway.
                unset($warehouse['warehouse_name'], $warehouse['status_name']);
                $warehouses[] = $warehouse;
            }
            $variant['warehouses'] = $warehouses;

            $channels = [];
            foreach ($variant['channels'] ?? [] as $channel) {
                $channelName = $channel['channel_name'] ?? null;
                $warehouseName = $channel['warehouse_name'] ?? null;
                if ($channelName === null
                    || $warehouseName === null
                    || ! isset($this->channelMap[$channelName])
                    || ! isset($this->warehouseMap[$warehouseName])
                ) {
                    continue;
                }
                $channel['channels_id'] = $this->channelMap[$channelName];
                $channel['warehouses_id'] = $this->warehouseMap[$warehouseName];
                unset($channel['channel_name'], $channel['warehouse_name']);
                $channels[] = $channel;
            }
            $variant['channels'] = $channels;

            if ($skipFiles) {
                $variant['files'] = [];
            }

            $record['variants'][$index] = $variant;
        }

        $record['warehouses'] = $productWarehouseNames === []
            ? [['warehouse' => 'default', 'channel' => 'default']]
            : array_map(
                fn (string $name) => ['warehouse' => $name, 'channel' => 'default'],
                array_keys($productWarehouseNames)
            );

        if (empty($record['productType'])) {
            unset($record['productType']);
        }

        if ($skipFiles) {
            $record['files'] = [];
        }

        return $record;
    }

    private function resolveFilePath(string $file): ?string
    {
        if ($file === '') {
            return null;
        }
        if (is_file($file)) {
            return $file;
        }
        $diskPath = Storage::disk('local')->path($file);

        return is_file($diskPath) ? $diskPath : null;
    }
}
