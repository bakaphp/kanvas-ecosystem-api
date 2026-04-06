<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Services\ProductsExportService;
use Kanvas\Notifications\Templates\Blank;

class InventoryExportProductsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:export-products {app_id} {company_id} {--email=*} {--template=inventory-products-export} {--subject=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Export inventory products to CSV and optionally send the file by email';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $fileName = sprintf(
            'inventory/products_export_app_%s_company_%s_%s.csv',
            $app->getId(),
            $company->getId(),
            now()->format('Y_m_d_H_i_s')
        );

        $exportService = new ProductsExportService($app, $company);
        $url = $exportService->toCsv($fileName, 'local');
        $filePath = Storage::disk('local')->path($fileName);
        $emails = collect((array) $this->option('email'))
            ->flatten()
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->values()
            ->all();
        $totalRecords = max(count(file($filePath)) - 1, 0);

        $this->info('Inventory export complete.');
        $this->line('App: ' . $app->name . ' (' . $app->getId() . ')');
        $this->line('Company: ' . $company->name . ' (' . $company->getId() . ')');
        $this->line('Rows exported: ' . $totalRecords);
        $this->line('File path: ' . $filePath);
        $this->line('File url: ' . $url);

        if ($emails !== []) {
            $template = (string) $this->option('template');
            $subject = $this->option('subject')
                ? (string) $this->option('subject')
                : 'Inventory Export - ' . $company->name;

            $notification = new Blank(
                $template,
                [
                    'app' => $app,
                    'company' => $company,
                    'totalRecords' => $totalRecords,
                    'filePath' => $filePath,
                    'fileName' => basename($filePath),
                ],
                ['mail'],
                $app,
                [[
                    'file' => $filePath,
                    'options' => [
                        'as' => basename($filePath),
                        'mime' => 'text/csv',
                    ],
                ]]
            );
            $notification->setSubject($subject);

            foreach ($emails as $email) {
                Notification::route('mail', $email)->notify($notification);
            }

            $this->info('Email sent to: ' . implode(', ', $emails));
            $this->line('Template: ' . $template);
        }

        return self::SUCCESS;
    }
}
