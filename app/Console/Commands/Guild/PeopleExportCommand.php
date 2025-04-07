<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use League\Csv\Writer;

class PeopleExportCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-guild:people-export {apps_id} {company_id} {emails}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Export all people to a single CSV and send via email';
    private string $csvFileName;
    private Writer $csv;
    private const CSV_BUFFER_SIZE = 1024 * 1024; // 1MB buffer

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $emails = explode(',', $this->argument('emails'));

        // Get total count for progress bar
        $totalCount = People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->count();

        $this->info("Starting export for App: {$app->name} and Company: {$company->name}");
        $this->info("Total records to process: {$totalCount}");

        try {
            // Initialize CSV with streaming configuration
            $this->initializeCsv();

            // Create progress bar
            $progressBar = $this->output->createProgressBar($totalCount);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

            // Use cursor for memory-efficient iteration
            $query = People::fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->orderBy('id', 'asc');

            // Process records using cursor
            foreach ($query->cursor() as $person) {
                $this->processRecord($person);
                $progressBar->advance();

                // Clear the entity manager every 100 records to free memory
                if ($progressBar->getProgress() % 100 === 0) {
                    gc_collect_cycles(); // Force garbage collection
                }
            }

            $progressBar->finish();
            $this->newLine();

            // Send email with the generated CSV
            $this->sendEmailWithCsv($emails);
        } catch (Exception $e) {
            $this->error('Export failed: ' . $e->getMessage());
            $this->cleanup();

            throw $e;
        }
    }

    private function initializeCsv(): void
    {
        $this->csvFileName = 'people_export_' . now()->format('Y_m_d_H_i_s') . '.csv';

        // Create CSV file with write buffer
        $filePath = Storage::disk('local')->path($this->csvFileName);
        $this->csv = Writer::createFromPath($filePath, 'w+');
        $this->csv->setOutputBOM(Writer::BOM_UTF8);

        // Set buffer size for memory efficiency
        if (method_exists($this->csv, 'setOutputBuffering')) {
            $this->csv->setOutputBuffering(self::CSV_BUFFER_SIZE);
        }

        // Insert headers
        $this->csv->insertOne([
            'Id',
            'First Name',
            'Last Name',
            'Full Name',
            'Email',
            'Location',
            'Title',
            'Company',
            'Company Type',
            'LinkedIn',
            'Tags',
            'Is VIP',
            'Created At',
        ]);
    }

    private function processRecord(People $person): void
    {
        $lastEmploymentHistory = $person->employmentHistory->first();
        $linkedIn = $person->contacts->first();

        $lastEmploymentHistory = $person->employmentHistory()->where('status', 1)->first()
        ?? $person->employmentHistory()->orderBy('start_date', 'desc')->first();
        $linkedIn = $person->contacts()
            ->where('contacts_types_id', ContactType::getByName('LinkedIn')->getId())
            ->get();

        $location = $person->address->count() ? $person->address->first()->city : null;
        if ($location == null) {
            $location = $person->get('location');
            $location = is_array($location) ? ($location['state'] ?? null) : $location;
        }

        $tags = $person->tags()->count() ? $person->tags()->pluck('name')->join(', ') : 'N/A';
        $companyPosition = $lastEmploymentHistory ? $lastEmploymentHistory->organization->name : ($person->get('company') ?? ($person->get('title') ?? 'N/A'));

        //add title to people employment history

        $this->csv->insertOne([
            $person->getId(),
            $person->firstname,
            $person->lastname,
            $person->name,
            $person->getEmails()->first()->value ?? 'N/A',
            $location ?? 'N/A',
            $lastEmploymentHistory ? $lastEmploymentHistory->position : 'N/A',
            $companyPosition,
            $person->get('company_type') ?? 'N/A',
            $linkedIn->count() ? $linkedIn->first()->value : 'N/A',
            $tags,
            $person->get('VIP') ? 'Yes' : 'No',
            $person->created_at->format('Y-m-d H:i:s'),
        ]);
    }

    private function sendEmailWithCsv(array $emails): void
    {
        $this->info('Preparing to send email...');

        $filePath = Storage::disk('local')->path($this->csvFileName);
        $fileSize = Storage::disk('local')->size($this->csvFileName);

        // Convert to MB for display
        $fileSizeMB = round($fileSize / 1048576, 2);
        $this->info("CSV file size: {$fileSizeMB}MB");

        try {
            Mail::raw(
                "Please find attached the CSV export for people.\n\nFile size: {$fileSizeMB}MB\nTotal records: {$this->getTotalRecords()}",
                function ($message) use ($emails, $filePath) {
                    $message->to($emails)
                        ->subject('People Export - Complete Dataset')
                        ->attach($filePath, [
                            'as' => $this->csvFileName,
                            'mime' => 'text/csv',
                        ]);
                }
            );

            $this->info('Email sent successfully.');
        } catch (Exception $e) {
            $this->error('Failed to send email: ' . $e->getMessage());
            $this->info('CSV file is still available at: ' . $filePath);

            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    private function getTotalRecords(): int
    {
        // Get total number of records in CSV (excluding header)
        return count(file(Storage::disk('local')->path($this->csvFileName))) - 1;
    }

    private function cleanup(): void
    {
        if (isset($this->csvFileName) && Storage::disk('local')->exists($this->csvFileName)) {
            Storage::disk('local')->delete($this->csvFileName);
        }
    }
}
